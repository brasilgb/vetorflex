<?php

namespace App\Http\Controllers\Pos;

use App\Http\Requests\Pos\PosCartRequest;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\Pos\Enums\CashMovementType;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Services\PosCashService;
use App\Modules\Pos\Services\PosCatalog;
use App\Modules\Pos\Services\PosCheckout;
use App\Modules\Pos\Services\PosModule;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Support\PosPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Frente de caixa em tela cheia. As ações respondem em JSON para que a tela trate quedas de conexão sem perder o
 * carrinho: a mesma chave de idempotência é reenviada, e nenhuma venda é dada como concluída sem resposta do servidor.
 */
class PosRegisterController extends PosController
{
    public function __construct(
        private PosCheckout $checkout,
        private PosSessionService $sessions,
        private PosCashService $cash,
        private PosCatalog $catalog,
        private PosModule $module,
        private PosPresenter $presenter,
        private StockModule $stock,
    ) {}

    public function show(Request $request, PosSession $session): Response
    {
        $company = $this->company($request);
        $this->sessions->assertOperable($session, $request->user());
        $settings = $this->module->settings($company);

        return Inertia::render('pos/register', [
            'session' => $this->presenter->session($session->load('terminal')),
            'favorites' => $this->catalog->favorites($company),
            'suspended' => $this->suspended($session),
            'paymentMethods' => array_map(fn (string $method): array => ['value' => $method, 'label' => PaymentMethod::from($method)->label()], $settings->paymentMethods),
            'settings' => [
                'allow_anonymous' => $settings->allowsAnonymousCustomer,
                'max_discount_percent' => $settings->maxDiscountPercent,
                'allow_price_change' => $settings->allowsPriceChange,
                'print_receipt' => $settings->printReceipt,
                'blind_close' => $session->is_blind,
            ],
            'abilities' => [
                'apply_discounts' => $this->can($request, PosPermission::ApplyDiscounts),
                'change_prices' => $settings->allowsPriceChange && $this->can($request, PosPermission::ChangePrices),
                'authorize' => $this->can($request, PosPermission::Authorize),
                'cancel' => $this->can($request, PosPermission::CancelSales),
                'withdraw' => $this->can($request, PosPermission::Withdraw),
                'supply' => $this->can($request, PosPermission::Supply),
                'close' => $this->can($request, PosPermission::CloseSession),
                'register_items' => $this->can($request, PosPermission::ManageSettings),
            ],
            'stockEnabled' => $this->stock->isEnabled($company),
            'today' => $this->today($request),
        ]);
    }

    public function finalize(PosCartRequest $request, PosSession $session): JsonResponse
    {
        $sale = $this->checkout->finalize($session, $request->user(), [
            'items' => $request->saleItems(),
            'discount' => $request->validated('discount'),
            'customer_party_id' => $request->validated('customer_party_id'),
            'payments' => $request->validated('payments') ?? [],
            'suspended_sale_id' => $request->validated('suspended_sale_id'),
            'authorization' => $this->credentials($request),
            'idempotency_key' => $request->validated('idempotency_key'),
        ]);

        return response()->json([
            'sale' => $this->presenter->sale($sale->load(['order', 'terminal', 'session'])),
            'payments' => $sale->payments->map(fn ($payment): array => $this->presenter->payment($payment))->values()->all(),
            'receipt_url' => route('pos.sales.receipt', $sale),
        ], 201);
    }

    public function suspend(PosCartRequest $request, PosSession $session): JsonResponse
    {
        $sale = $this->checkout->suspend($session, $request->user(), [
            'items' => $request->validated('items'),
            'discount' => $request->validated('discount'),
            'customer_party_id' => $request->validated('customer_party_id'),
            'customer_name' => $request->validated('customer_name'),
            'estimated_total' => $request->validated('estimated_total'),
        ], $request->validated('label'), $request->validated('idempotency_key'), $request->validated('suspended_sale_id'));

        return response()->json(['sale' => $this->presenter->sale($sale->load(['terminal', 'session'])), 'suspended' => $this->suspended($session)], 201);
    }

    public function resume(Request $request, PosSession $session, PosSale $sale): JsonResponse
    {
        $sale = $this->checkout->resume($sale, $session, $request->user());

        return response()->json(['sale' => $this->presenter->sale($sale->load(['terminal', 'session'])), 'cart' => $sale->cart]);
    }

    public function cancel(Request $request, PosSession $session): JsonResponse
    {
        $validated = $request->validate([
            'pos_sale_id' => ['nullable', 'integer'],
            'cart' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'max:400'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['reason.required' => 'Informe o motivo do cancelamento.']);
        $sale = isset($validated['pos_sale_id']) ? PosSale::query()->where('company_id', $session->company_id)->findOrFail((int) $validated['pos_sale_id']) : null;

        if ($sale === null && ($validated['cart']['items'] ?? []) === []) {
            throw ValidationException::withMessages(['cart' => 'Não há venda em andamento para cancelar.']);
        }

        $cancelled = $this->checkout->cancel($session, $request->user(), $sale, $validated['cart'] ?? null, $validated['reason'], $this->credentials($request), $validated['idempotency_key']);

        return response()->json(['sale' => $this->presenter->sale($cancelled->load(['terminal', 'session'])), 'suspended' => $this->suspended($session)]);
    }

    public function cashMovement(Request $request, PosSession $session): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([CashMovementType::Supply->value, CashMovementType::Withdrawal->value, CashMovementType::Adjustment->value])],
            'amount' => ['required', 'regex:/^-?\d{1,13}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:400'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['amount.required' => 'Informe o valor.', 'amount.regex' => 'Informe um valor válido.', 'reason.required' => 'Informe o motivo.']);
        $movement = $this->cash->record($session, $request->user(), CashMovementType::from($validated['type']), $validated['amount'], $validated['reason'], $this->credentials($request), $validated['idempotency_key']);

        return response()->json(['movement' => $this->presenter->movement($movement)], 201);
    }

    /** Resumo para o fechamento. No fechamento cego, o valor esperado não é exibido ao operador. */
    public function summary(Request $request, PosSession $session): JsonResponse
    {
        $summary = $this->sessions->summary($session);

        if ($session->is_blind && $session->isOpen() && ! $this->can($request, PosPermission::Authorize)) {
            unset($summary['expected_cash']);
        }

        return response()->json($summary);
    }

    /** @return list<array<string, mixed>> */
    private function suspended(PosSession $session): array
    {
        return array_values(PosSale::query()->where('company_id', $session->company_id)->where('status', PosSaleStatus::Suspended)->with(['terminal', 'session'])->latest('suspended_at')->limit(50)->get()
            ->map(fn (PosSale $sale): array => $this->presenter->sale($sale))->values()->all());
    }
}

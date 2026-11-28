<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Data\PosSettings;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Events\PosSaleCompleted;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Data\SaleChannel;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleOrderItem;
use App\Modules\Sales\Services\QuickSaleService;
use App\Modules\Sales\Services\SaleOrderService;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finalização, suspensão, recuperação e cancelamento de atendimentos no caixa.
 *
 * A venda é registrada pelo serviço comercial compartilhado (`QuickSaleService`): mesma entidade, numeração,
 * documentos, estoque e eventos de Vendas, com origem PDV. Os valores são sempre recalculados no servidor; depois do
 * cálculo do total, o PDV define os pagamentos combinados (`SaleOrderService::schedulePayment`). Repetir a
 * finalização com a mesma chave devolve a venda já registrada.
 *
 * @phpstan-type PaymentRow array{method: string, amount: string, is_term?: bool, due_date?: string|null, payment_charge_id?: int|null}
 * @phpstan-type Plan array{condition: PaymentCondition, method: string|null, installments: list<array{due_date: string, amount: string, method: string}>, payments: list<array{sequence: int, method: string, amount: string, tendered: string|null, change: string, is_received: bool, due_date: string, schedule_sequence: int|null, payment_charge_id: int|null, is_provider_confirmed: bool}>, received: string, term: string, change: string}
 */
class PosCheckout
{
    public function __construct(
        private QuickSaleService $quickSales,
        private SaleOrderService $orders,
        private PosSessionService $sessions,
        private PosAuthorizer $authorizer,
        private PosModule $module,
        private AuditService $audit,
        private PosPix $pix,
    ) {}

    /**
     * Dados: `items`, `discount`, `customer_party_id`, `payments` (forma, valor, a prazo e vencimento), `idempotency_key`,
     * `suspended_sale_id` (ao concluir uma venda suspensa) e `authorization` (credenciais de supervisor).
     *
     * @param  array<string, mixed>  $data
     */
    public function finalize(PosSession $session, User $operator, array $data): PosSale
    {
        $company = Company::query()->findOrFail($session->company_id);
        $this->sessions->assertOperable($session, $operator);
        $settings = $this->module->settings($company);
        $credentials = $data['authorization'] ?? null;
        $today = now($company->timezone)->format('Y-m-d');
        $replay = $this->replay($company, (string) $data['idempotency_key']);

        if ($replay !== null) {
            return $replay;
        }

        foreach ($data['payments'] ?? [] as $index => $payment) {
            if (! in_array($payment['method'], $settings->paymentMethods, true)) {
                throw ValidationException::withMessages(["payments.{$index}.method" => 'Forma de pagamento não habilitada no PDV.']);
            }
        }

        [$abilities, $supervisor] = $this->abilities($company, $operator, $settings, $data, $credentials);
        $channel = new SaleChannel(SaleOrigin::PointOfSale, $session->reference(), $settings->allowsAnonymousCustomer);
        $orderData = [
            'customer_party_id' => $data['customer_party_id'] ?? null,
            'order_date' => $today,
            'delivery_method' => 'pickup',
            'discount' => (string) ($data['discount'] ?? '0'),
            'freight' => '0',
            'other_costs' => '0',
            'payment_condition' => PaymentCondition::Undefined->value,
            'installments' => [],
            'items' => $data['items'],
            'idempotency_key' => "pos:{$data['idempotency_key']}",
        ];

        try {
            return DB::transaction(function () use ($company, $session, $operator, $data, $settings, $credentials, $today, $abilities, $channel, $orderData, &$supervisor): PosSale {
                /** @var Plan|null $plan */
                $plan = null;
                $order = $this->quickSales->register($company, $operator, $orderData, $abilities, $channel, function (SaleOrder $order) use ($company, $operator, $settings, $credentials, $data, $today, &$plan, &$supervisor): SaleOrder {
                    $supervisor = $this->checkDiscountLimit($company, $order, $operator, $settings, $credentials) ?? $supervisor;
                    $plan = $this->plan($order, $data['payments'] ?? [], $today);

                    return $this->orders->schedulePayment($order, $plan['condition'], $plan['method'], $plan['installments']);
                });

                if ($plan === null) {
                    return PosSale::query()->where('company_id', $company->id)->where('sale_order_id', $order->id)->firstOrFail();
                }

                $this->pix->claim($company, $plan['payments'], $order);
                $sale = $this->record($company, $session, $operator, $order, $plan, $data, $supervisor);
                $this->audit->record('pos.sale_completed', $company, $operator, $sale, ['order' => $order->number, 'total' => $order->total, 'session' => $session->number]);
                PosSaleCompleted::dispatch($company->id, $sale->id, $order->id, array_map(fn (array $payment): array => [
                    'sequence' => $payment['sequence'],
                    'schedule_sequence' => $payment['schedule_sequence'],
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'is_received' => $payment['is_received'],
                    'date' => $payment['due_date'],
                    'account_id' => $settings->financeAccounts[$payment['method']] ?? null,
                ], $plan['payments']), $operator->id);

                return $sale;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->replay($company, (string) $data['idempotency_key']) ?? throw $exception;
        }
    }

    /**
     * Guarda o carrinho sem concluir a venda: nenhuma reserva, saída de estoque ou movimento financeiro.
     *
     * @param  array<string, mixed>  $cart
     */
    public function suspend(PosSession $session, User $operator, array $cart, ?string $label, string $idempotencyKey, ?int $suspendedSaleId = null): PosSale
    {
        $this->sessions->assertOperable($session, $operator);

        if (($cart['items'] ?? []) === []) {
            throw ValidationException::withMessages(['items' => 'Inclua ao menos um item antes de suspender.']);
        }

        return DB::transaction(function () use ($session, $operator, $cart, $label, $idempotencyKey, $suspendedSaleId): PosSale {
            $sale = $suspendedSaleId === null
                ? PosSale::query()->where('company_id', $session->company_id)->where('idempotency_key', $idempotencyKey)->first()
                : $this->lockSuspended($session->company_id, $suspendedSaleId);
            $attributes = [
                'pos_session_id' => $session->id,
                'pos_terminal_id' => $session->pos_terminal_id,
                'status' => PosSaleStatus::Suspended,
                'user_id' => $operator->id,
                'user_name' => $operator->name,
                'customer_party_id' => $cart['customer_party_id'] ?? null,
                'label' => $label,
                'cart' => $cart,
                'suspended_at' => now(),
            ];

            if ($sale === null) {
                $sale = PosSale::create([...$attributes, 'company_id' => $session->company_id, 'idempotency_key' => $idempotencyKey]);
            } elseif ($suspendedSaleId !== null) {
                $sale->update($attributes);
            }

            $this->audit->record('pos.sale_suspended', null, $operator, $sale, ['session' => $session->number, 'items' => count($cart['items'])]);

            return $sale;
        });
    }

    /** Recupera uma venda suspensa no caixa atual. Preços, disponibilidade e permissões são validados de novo ao concluir. */
    public function resume(PosSale $sale, PosSession $session, User $operator): PosSale
    {
        $this->sessions->assertOperable($session, $operator);

        return DB::transaction(function () use ($sale, $session, $operator): PosSale {
            $sale = $this->lockSuspended($session->company_id, $sale->id);
            $sale->update(['pos_session_id' => $session->id, 'pos_terminal_id' => $session->pos_terminal_id]);
            $this->audit->record('pos.sale_resumed', null, $operator, $sale, ['session' => $session->number]);

            return $sale;
        });
    }

    /**
     * Cancela uma venda suspensa, ou registra o cancelamento do carrinho em andamento para preservar o histórico.
     *
     * @param  array<string, mixed>|null  $cart  Carrinho em andamento (quando não há venda suspensa).
     * @param  array{email?: string|null, password?: string|null}|null  $credentials
     */
    public function cancel(PosSession $session, User $operator, ?PosSale $sale, ?array $cart, string $reason, ?array $credentials, string $idempotencyKey): PosSale
    {
        $company = Company::query()->findOrFail($session->company_id);
        $this->sessions->assertOperable($session, $operator);
        $authorizer = $this->authorizer->authorize($company, $operator, PosPermission::CancelSales, $credentials, 'Cancelar venda');

        return DB::transaction(function () use ($company, $session, $operator, $sale, $cart, $reason, $authorizer, $idempotencyKey): PosSale {
            $values = ['status' => PosSaleStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by_name' => $operator->name, 'cancellation_reason' => $reason, 'authorized_by_name' => $authorizer->is($operator) ? null : $authorizer->name];

            if ($sale !== null) {
                $sale = $this->lockSuspended($company->id, $sale->id);
                $sale->update($values);
            } else {
                $sale = PosSale::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first()
                    ?? PosSale::create([...$values, 'company_id' => $company->id, 'pos_session_id' => $session->id, 'pos_terminal_id' => $session->pos_terminal_id, 'user_id' => $operator->id, 'user_name' => $operator->name, 'cart' => $cart, 'customer_party_id' => $cart['customer_party_id'] ?? null, 'idempotency_key' => $idempotencyKey]);
            }

            $this->audit->record('pos.sale_cancelled', $company, $operator, $sale, ['reason' => $reason, 'authorized_by' => $authorizer->name]);

            return $sale;
        });
    }

    /**
     * Distribui o total calculado pelo servidor entre os pagamentos informados. Troco só existe em dinheiro; valores
     * a prazo exigem cliente identificado e ficam em aberto. Pix, cartões e demais formas são registrados como
     * informados pelo operador, sem confirmação bancária.
     *
     * @param  list<PaymentRow>  $payments
     * @return Plan
     */
    public function plan(SaleOrder $order, array $payments, string $today): array
    {
        $total = BigDecimal::of($order->total);

        if ($total->isZero()) {
            return ['condition' => PaymentCondition::Undefined, 'method' => null, 'installments' => [], 'payments' => [], 'received' => '0.00', 'term' => '0.00', 'change' => '0.00'];
        }

        $cashTendered = BigDecimal::zero();
        $others = [];

        foreach ($payments as $index => $payment) {
            $amount = BigDecimal::of(CommercialMath::money((string) $payment['amount']));
            $isTerm = (bool) ($payment['is_term'] ?? false);

            if (! $amount->isPositive()) {
                continue;
            }

            if ($payment['method'] === PaymentMethod::Cash->value) {
                if ($isTerm) {
                    throw ValidationException::withMessages(["payments.{$index}.is_term" => 'Dinheiro não pode ser a prazo.']);
                }

                $cashTendered = $cashTendered->plus($amount);

                continue;
            }

            $dueDate = $isTerm ? (string) ($payment['due_date'] ?? '') : $today;

            if ($isTerm && ($dueDate === '' || $dueDate < $today)) {
                throw ValidationException::withMessages(["payments.{$index}.due_date" => 'Informe um vencimento a partir de hoje.']);
            }

            if ($isTerm && $order->customer_party_id === null) {
                throw ValidationException::withMessages(['customer_party_id' => 'Venda a prazo exige cliente identificado.']);
            }

            $chargeId = isset($payment['payment_charge_id']) ? (int) $payment['payment_charge_id'] : null;

            if ($chargeId !== null && ($payment['method'] !== PaymentMethod::Pix->value || $isTerm)) {
                throw ValidationException::withMessages(["payments.{$index}.payment_charge_id" => 'Somente pagamentos Pix à vista podem ser confirmados pelo provedor.']);
            }

            $others[] = ['method' => $payment['method'], 'amount' => $amount, 'is_received' => ! $isTerm, 'due_date' => $dueDate, 'payment_charge_id' => $chargeId];
        }

        $othersTotal = array_reduce($others, fn (BigDecimal $carry, array $row): BigDecimal => $carry->plus($row['amount']), BigDecimal::zero());

        if ($othersTotal->isGreaterThan($total)) {
            throw ValidationException::withMessages(['payments' => 'Os pagamentos superam o total da venda ('.CommercialMath::money($total).'). Troco só é dado em dinheiro.']);
        }

        $cashDue = $total->minus($othersTotal);

        if ($cashTendered->isLessThan($cashDue)) {
            throw ValidationException::withMessages(['payments' => 'Falta receber '.CommercialMath::money($cashDue->minus($cashTendered)).'.']);
        }

        $rows = $others;

        if ($cashDue->isPositive()) {
            $rows[] = ['method' => PaymentMethod::Cash->value, 'amount' => $cashDue, 'is_received' => true, 'due_date' => $today, 'tendered' => $cashTendered, 'change' => $cashTendered->minus($cashDue)];
        }

        if (count($rows) > PaymentCondition::Combined->scheduleLimits()[1]) {
            throw ValidationException::withMessages(['payments' => 'Use no máximo 10 formas de pagamento.']);
        }

        // Mesma ordenação (estável) de PaymentSchedule::build, para ligar cada pagamento ao seu vencimento.
        usort($rows, fn (array $first, array $second): int => strcmp($first['due_date'], $second['due_date']));
        $change = $cashTendered->minus($cashDue);
        $planned = [];
        $received = BigDecimal::zero();
        $term = BigDecimal::zero();

        foreach ($rows as $index => $row) {
            if ($row['is_received']) {
                $received = $received->plus($row['amount']);
            } else {
                $term = $term->plus($row['amount']);
            }

            $planned[] = [
                'sequence' => $index + 1,
                'method' => $row['method'],
                'amount' => CommercialMath::money($row['amount']),
                'tendered' => isset($row['tendered']) ? CommercialMath::money($row['tendered']) : null,
                'change' => isset($row['change']) ? CommercialMath::money($row['change']) : '0.00',
                'is_received' => $row['is_received'],
                'due_date' => $row['due_date'],
                'schedule_sequence' => $index + 1,
                'payment_charge_id' => $row['payment_charge_id'] ?? null,
                'is_provider_confirmed' => isset($row['payment_charge_id']),
            ];
        }

        $single = count($planned) === 1 ? $planned[0] : null;

        return [
            'condition' => $single === null ? PaymentCondition::Combined : ($single['is_received'] ? PaymentCondition::Cash : PaymentCondition::Term),
            'method' => $single['method'] ?? null,
            'installments' => array_map(fn (array $row): array => ['due_date' => $row['due_date'], 'amount' => $row['amount'], 'method' => $row['method']], $planned),
            'payments' => $planned,
            'received' => CommercialMath::money($received),
            'term' => CommercialMath::money($term),
            'change' => CommercialMath::money($change),
        ];
    }

    /**
     * Desconto acima do limite configurado exige `pos.authorize` (ou credenciais de supervisor), se a configuração permitir.
     *
     * @param  array{email?: string|null, password?: string|null}|null  $credentials
     */
    private function checkDiscountLimit(Company $company, SaleOrder $order, User $operator, PosSettings $settings, ?array $credentials): ?User
    {
        $gross = $order->items()->get()->reduce(fn (BigDecimal $carry, SaleOrderItem $item): BigDecimal => $carry->plus(BigDecimal::of($item->quantity)->multipliedBy($item->unit_price)->toScale(2, RoundingMode::HalfUp)), BigDecimal::zero());
        $discount = $gross->minus($order->total);

        if (! $gross->isPositive() || ! $discount->isPositive()) {
            return null;
        }

        $percent = $discount->multipliedBy(100)->dividedBy($gross, 2, RoundingMode::HalfUp);

        if (! $percent->isGreaterThan($settings->maxDiscountPercent)) {
            return null;
        }

        $limit = str_replace('.', ',', CommercialMath::money($settings->maxDiscountPercent));

        if (! $settings->discountAboveLimitRequiresAuthorization) {
            throw ValidationException::withMessages(['discount' => "Desconto de {$percent}% acima do limite de {$limit}% do PDV."]);
        }

        $authorizer = $this->authorizer->authorize($company, $operator, null, $credentials, "Desconto de {$percent}% acima do limite de {$limit}%");

        return $authorizer->is($operator) ? null : $authorizer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{email?: string|null, password?: string|null}|null  $credentials
     * @return array{SaleAbilities, User|null}
     */
    private function abilities(Company $company, User $operator, PosSettings $settings, array $data, ?array $credentials): array
    {
        $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        $wantsDiscount = BigDecimal::of((string) ($data['discount'] ?? '0'))->isPositive()
            || collect($items)->contains(fn (array $item): bool => BigDecimal::of((string) ($item['discount'] ?? '0'))->isPositive() || BigDecimal::of((string) ($item['discount_percent'] ?? '0'))->isPositive());
        $wantsPriceChange = collect($items)->contains(fn (array $item): bool => isset($item['product_variant_id']) && filled($item['unit_price'] ?? null));
        $supervisor = null;
        $canDiscount = $this->authorizer->can($company, $operator, PosPermission::ApplyDiscounts);
        $canChangePrices = $settings->allowsPriceChange && $this->authorizer->can($company, $operator, PosPermission::ChangePrices);

        if ($wantsDiscount && ! $canDiscount) {
            $supervisor = $this->authorizer->authorize($company, $operator, PosPermission::ApplyDiscounts, $credentials, 'Aplicar desconto');
            $canDiscount = true;
        }

        if ($wantsPriceChange && $settings->allowsPriceChange && ! $canChangePrices) {
            $supervisor = $this->authorizer->authorize($company, $operator, PosPermission::ChangePrices, $credentials, 'Alterar preço');
            $canChangePrices = true;
        }

        return [new SaleAbilities(canChangePrices: $canChangePrices, canApplyDiscounts: $canDiscount, canUseCustomItems: true), $supervisor !== null && ! $supervisor->is($operator) ? $supervisor : null];
    }

    /**
     * @param  Plan  $plan
     * @param  array<string, mixed>  $data
     */
    private function record(Company $company, PosSession $session, User $operator, SaleOrder $order, array $plan, array $data, ?User $supervisor): PosSale
    {
        $values = [
            'pos_session_id' => $session->id,
            'pos_terminal_id' => $session->pos_terminal_id,
            'sale_order_id' => $order->id,
            'status' => PosSaleStatus::Completed,
            'user_id' => $operator->id,
            'user_name' => $operator->name,
            'customer_party_id' => $order->customer_party_id,
            'cart' => null,
            'total' => $order->total,
            'received_total' => $plan['received'],
            'term_total' => $plan['term'],
            'change_total' => $plan['change'],
            'authorized_by_name' => $supervisor?->name,
            'completed_at' => now(),
        ];
        $sale = isset($data['suspended_sale_id'])
            ? tap($this->lockSuspended($company->id, (int) $data['suspended_sale_id']))->update($values)
            : PosSale::create([...$values, 'company_id' => $company->id, 'idempotency_key' => (string) $data['idempotency_key']]);

        foreach ($plan['payments'] as $payment) {
            PosPayment::create([...$payment, 'company_id' => $company->id, 'pos_sale_id' => $sale->id]);
        }

        return $sale->refresh();
    }

    private function lockSuspended(int $companyId, int $saleId): PosSale
    {
        $sale = PosSale::query()->where('company_id', $companyId)->lockForUpdate()->find($saleId);

        if ($sale === null || $sale->status !== PosSaleStatus::Suspended) {
            throw ValidationException::withMessages(['suspended_sale_id' => 'Esta venda suspensa não está mais disponível.']);
        }

        return $sale;
    }

    /** Venda já concluída com a mesma chave (clique repetido ou reenvio após queda de conexão). */
    private function replay(Company $company, string $key): ?PosSale
    {
        $orderId = SaleOrder::query()->where('company_id', $company->id)->where('idempotency_key', "pos:{$key}")->value('id');

        return $orderId === null ? null : PosSale::query()->where('company_id', $company->id)->where('sale_order_id', $orderId)->first();
    }
}

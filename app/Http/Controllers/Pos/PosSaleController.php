<?php

namespace App\Http\Controllers\Pos;

use App\Http\Requests\Pos\PosReturnRequest;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Enums\PosSessionStatus;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosModule;
use App\Modules\Pos\Services\PosReturnService;
use App\Modules\Pos\Support\PosPresenter;
use App\Modules\Pos\Support\PosReceipt;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Models\SaleFulfillmentItem;
use App\Modules\Sales\Models\SaleOrderItem;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendas registradas no caixa: histórico, detalhe, devoluções e comprovantes não fiscais. A segunda via exige
 * `pos.reprint` e é contada; a via do próprio atendimento fica disponível ao operador enquanto o caixa está aberto.
 */
class PosSaleController extends PosController
{
    public function __construct(private PosPresenter $presenter, private PosReceipt $receipts, private PosReturnService $returns, private PosModule $module, private AuditService $audit) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $filters = $request->validate(['status' => ['nullable', 'in:completed,suspended,cancelled'], 'terminal' => ['nullable', 'integer'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'number' => ['nullable', 'integer']]);

        return Inertia::render('pos/sales/index', [
            'sales' => PosSale::query()->where('company_id', $company->id)->with(['order', 'terminal', 'session'])
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($filters['terminal'] ?? null, fn (Builder $query, int $terminal) => $query->where('pos_terminal_id', $terminal))
                ->when($filters['number'] ?? null, fn (Builder $query, int $number) => $query->whereHas('order', fn (Builder $order) => $order->where('number', $number)))
                ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', now($company->timezone)->parse($from, $company->timezone)->startOfDay()->utc()))
                ->when($filters['until'] ?? null, fn (Builder $query, string $until) => $query->where('created_at', '<=', now($company->timezone)->parse($until, $company->timezone)->endOfDay()->utc()))
                ->latest('id')->paginate(30)->withQueryString()
                ->through(fn (PosSale $sale): array => $this->presenter->sale($sale)),
            'filters' => ['status' => $filters['status'] ?? null, 'terminal' => isset($filters['terminal']) ? (int) $filters['terminal'] : null, 'from' => $filters['from'] ?? null, 'until' => $filters['until'] ?? null, 'number' => isset($filters['number']) ? (int) $filters['number'] : null],
            'terminals' => PosTerminal::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])->map(fn (PosTerminal $terminal): array => ['id' => $terminal->id, 'name' => $terminal->name])->values()->all(),
        ]);
    }

    public function show(Request $request, PosSale $sale): Response
    {
        $company = $this->company($request);
        $sale->load(['order.items', 'terminal', 'session', 'payments', 'refunds.saleReturn']);
        $fulfilled = $sale->order === null ? collect() : SaleFulfillmentItem::query()->whereHas('fulfillment', fn (Builder $query) => $query->where('sale_order_id', $sale->sale_order_id)->where('status', FulfillmentStatus::Confirmed))->get()->keyBy('sale_order_item_id');

        return Inertia::render('pos/sales/show', [
            'sale' => $this->presenter->sale($sale),
            'items' => $sale->order === null
                ? array_values(array_map(fn (array $item): array => ['id' => null, 'description' => $item['name'] ?? $item['description'] ?? 'Item', 'quantity' => (string) $item['quantity'], 'unit_code' => $item['unit_code'] ?? 'UN', 'unit_price' => $item['unit_price'] ?? null, 'discount' => $item['discount'] ?? '0', 'total' => null, 'returnable' => '0', 'allows_fraction' => (bool) ($item['allows_fraction'] ?? false)], $sale->cart['items'] ?? []))
                : $sale->order->items->map(fn (SaleOrderItem $item): array => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_code' => $item->unit_code,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'total' => $item->total,
                    'returnable' => $fulfilled->has($item->id) ? (string) BigDecimal::of($fulfilled[$item->id]->quantity)->minus($fulfilled[$item->id]->returned_quantity) : '0',
                    'allows_fraction' => $item->allows_fraction,
                ])->values()->all(),
            'payments' => $sale->payments->map(fn (PosPayment $payment): array => $this->presenter->payment($payment))->values()->all(),
            'refunds' => $sale->refunds->map(fn (PosRefund $refund): array => $this->presenter->refund($refund))->values()->all(),
            'refundMethods' => array_map(fn (string $method): array => ['value' => $method, 'label' => PaymentMethod::from($method)->label()], $this->module->settings($company)->paymentMethods),
            'openSession' => PosSession::query()->where('company_id', $company->id)->where('status', PosSessionStatus::Open)->where('user_id', $request->user()->id)->value('id'),
            'salesEnabled' => $this->module->isEnabled($company, 'sales'),
            'canReturn' => $sale->status === PosSaleStatus::Completed,
            'canReprint' => $this->can($request, PosPermission::Reprint),
        ]);
    }

    public function storeReturn(PosReturnRequest $request, PosSale $sale): RedirectResponse
    {
        $company = $this->company($request);
        $session = $request->filled('pos_session_id')
            ? PosSession::query()->where('company_id', $company->id)->findOrFail((int) $request->validated('pos_session_id'))
            : PosSession::query()->where('company_id', $company->id)->where('status', PosSessionStatus::Open)->where('user_id', $request->user()->id)->first();
        $refund = $this->returns->register($sale, $request->user(), $session, [...$request->validated(), 'authorization' => $this->credentials($request)]);
        $this->toast(BigDecimal::of($refund->amount)->isPositive() ? "Devolução registrada. Restituir R$ {$refund->amount} em {$refund->method->label()}." : 'Devolução registrada, sem valor a restituir.');

        return back();
    }

    public function receipt(Request $request, PosSale $sale): Response
    {
        $isCopy = $this->authorizeReceipt($request, $sale);
        $width = (int) $request->integer('width', $sale->terminal->receiptWidth());

        return Inertia::render('pos/receipt', [
            'receipt' => $this->receipts->data($sale->load(['terminal', 'session', 'payments']), $isCopy),
            'width' => $width === 58 ? 58 : 80,
            'saleId' => $sale->id,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function pdf(Request $request, PosSale $sale): HttpResponse
    {
        $isCopy = $this->authorizeReceipt($request, $sale);

        return response($this->receipts->pdf($sale->load(['terminal', 'session', 'payments']), $isCopy), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"comprovante-{$sale->id}.pdf\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return bool Se é segunda via (contada e auditada). */
    private function authorizeReceipt(Request $request, PosSale $sale): bool
    {
        abort_unless($sale->status === PosSaleStatus::Completed, 404);
        $isFirstCopy = $sale->user_id === $request->user()->id && $sale->session()->value('status') === PosSessionStatus::Open && ! $request->boolean('copy');

        if ($isFirstCopy) {
            return false;
        }

        abort_unless($this->can($request, PosPermission::Reprint), 403);
        $sale->increment('reprints');
        $this->audit->record('pos.receipt_reprinted', $this->company($request), $request->user(), $sale, ['reprints' => $sale->reprints]);

        return true;
    }
}

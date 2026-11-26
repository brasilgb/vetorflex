<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\PurchaseReasonRequest;
use App\Http\Requests\Purchases\ReceivePurchaseOrderRequest;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Services\PurchaseReceiptService;
use App\Modules\Purchases\Support\PurchasePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReceiptController extends PurchaseController
{
    public const FILTERS = ['search', 'status', 'from', 'until'];

    public function __construct(private PurchaseReceiptService $receipts, private PurchasePresenter $presenter) {}

    /** Recebimentos registrados e, acima deles, os pedidos que ainda aguardam mercadorias. */
    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $canViewCost = $this->canViewCost($request);
        $today = $this->today($request);
        $search = $request->string('search')->trim()->value();
        $status = ReceiptStatus::tryFrom((string) $request->input('status'));

        return Inertia::render('purchases/receipts', [
            'receipts' => PurchaseReceipt::query()->where('company_id', $company->id)->with('order')
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('document_reference', 'like', "%{$search}%")
                    ->when(ctype_digit(ltrim($search, '#')), fn (Builder $query) => $query->orWhere('number', (int) ltrim($search, '#'))
                        ->orWhereHas('order', fn (Builder $order) => $order->where('number', (int) ltrim($search, '#'))))
                    ->orWhereHas('order.supplier.party', fn (Builder $party) => $party->where('name', 'like', "%{$search}%")->orWhere('trade_name', 'like', "%{$search}%"))))
                ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
                ->when($request->date('from'), fn (Builder $query, $from) => $query->where('received_at', '>=', $from->format('Y-m-d')))
                ->when($request->date('until'), fn (Builder $query, $until) => $query->where('received_at', '<=', $until->format('Y-m-d')))
                ->orderByDesc('received_at')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (PurchaseReceipt $receipt): array => [
                    ...$this->presenter->receipt($receipt, $canViewCost),
                    'order_number' => $receipt->order->number,
                    'supplier' => $receipt->order->supplier_snapshot['name'] ?? '',
                ]),
            'pendingOrders' => PurchaseOrder::query()->where('company_id', $company->id)->pending()
                ->orderByRaw('expected_date is null')->orderBy('expected_date')->orderBy('id')->limit(20)->get()
                ->map(fn (PurchaseOrder $order): array => $this->presenter->orderSummary($order, $canViewCost, $today)),
            'filters' => $request->only(self::FILTERS),
            'statuses' => array_map(fn (ReceiptStatus $status): array => ['value' => $status->value, 'label' => $status->label()], ReceiptStatus::cases()),
        ]);
    }

    public function store(ReceivePurchaseOrderRequest $request, PurchaseOrder $order): RedirectResponse
    {
        $receipt = $this->receipts->receive($order, $request->user(), $request->validated());
        $this->toast("Recebimento #{$receipt->number} registrado. O estoque foi atualizado.");

        return to_route('purchases.orders.show', $order);
    }

    /** Estorno técnico de um recebimento lançado por engano. Não se confunde com a devolução ao fornecedor. */
    public function reverse(PurchaseReasonRequest $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->receipts->reverse($receipt, $request->user(), $request->validated('reason'));
        $this->toast("Recebimento #{$receipt->number} estornado.");

        return back();
    }
}

<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\StorePurchaseReturnRequest;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Services\PurchaseReturnService;
use App\Modules\Purchases\Support\PurchasePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReturnController extends PurchaseController
{
    public const FILTERS = ['search', 'from', 'until'];

    public function __construct(private PurchaseReturnService $returns, private PurchasePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $canViewCost = $this->canViewCost($request);
        $search = $request->string('search')->trim()->value();

        return Inertia::render('purchases/returns', [
            'returns' => PurchaseReturn::query()->where('company_id', $company->id)->with(['order', 'receipt', 'items.receiptItem.orderItem'])
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('reason', 'like', "%{$search}%")
                    ->when(ctype_digit(ltrim($search, '#')), fn (Builder $query) => $query->orWhere('number', (int) ltrim($search, '#'))
                        ->orWhereHas('order', fn (Builder $order) => $order->where('number', (int) ltrim($search, '#'))))
                    ->orWhereHas('order.supplier.party', fn (Builder $party) => $party->where('name', 'like', "%{$search}%")->orWhere('trade_name', 'like', "%{$search}%"))))
                ->when($request->date('from'), fn (Builder $query, $from) => $query->where('returned_at', '>=', $from->format('Y-m-d')))
                ->when($request->date('until'), fn (Builder $query, $until) => $query->where('returned_at', '<=', $until->format('Y-m-d')))
                ->orderByDesc('returned_at')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (PurchaseReturn $return): array => [
                    ...$this->presenter->purchaseReturn($return, $canViewCost),
                    'order_number' => $return->order->number,
                    'supplier' => $return->order->supplier_snapshot['name'] ?? '',
                ]),
            'filters' => $request->only(self::FILTERS),
        ]);
    }

    public function store(StorePurchaseReturnRequest $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $return = $this->returns->register($receipt, $request->user(), $request->validated());
        $this->toast("Devolução #{$return->number} registrada. O estoque foi atualizado.");

        return to_route('purchases.orders.show', $receipt->purchase_order_id);
    }
}

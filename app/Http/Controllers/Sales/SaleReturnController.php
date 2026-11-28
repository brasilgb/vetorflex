<?php

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Services\SaleReturnService;
use App\Modules\Sales\Support\SalePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleReturnController extends SalesController
{
    public function __construct(private SaleReturnService $returns, private SalePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $canViewCost = $this->canViewCost($request);
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('sales/returns', [
            'returns' => SaleReturn::query()->where('company_id', $company->id)
                ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query->where('returned_at', '>=', $from))
                ->when($validated['until'] ?? null, fn (Builder $query, string $until) => $query->where('returned_at', '<=', $until))
                ->with(['order', 'fulfillment', 'items.fulfillmentItem.orderItem'])->orderByDesc('returned_at')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (SaleReturn $return): array => $this->presenter->saleReturn($return, $canViewCost)),
            'filters' => $request->only(['from', 'until']),
        ]);
    }

    public function store(StoreSaleReturnRequest $request, SaleFulfillment $fulfillment): RedirectResponse
    {
        $return = $this->returns->register($fulfillment, $request->user(), $request->validated());
        $this->toast("Devolução #{$return->number} registrada.");

        return back();
    }
}

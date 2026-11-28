<?php

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\FulfillSaleOrderRequest;
use App\Http\Requests\Sales\SaleReasonRequest;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Services\SaleFulfillmentService;
use App\Modules\Sales\Support\SalePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleFulfillmentController extends SalesController
{
    public function __construct(private SaleFulfillmentService $fulfillments, private SalePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $canViewCost = $this->canViewCost($request);
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'in:confirmed,reversed'], 'search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('sales/fulfillments', [
            'fulfillments' => SaleFulfillment::query()->where('company_id', $company->id)
                ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query->where('fulfilled_at', '>=', $from))
                ->when($validated['until'] ?? null, fn (Builder $query, string $until) => $query->where('fulfilled_at', '<=', $until))
                ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when(ctype_digit(ltrim($search, '#')), fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('number', (int) ltrim($search, '#'))))
                ->with(['order', 'items.orderItem', 'returns'])->orderByDesc('fulfilled_at')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (SaleFulfillment $fulfillment): array => $this->presenter->fulfillment($fulfillment, $canViewCost)),
            'filters' => $request->only(['from', 'until', 'status', 'search']),
        ]);
    }

    public function store(FulfillSaleOrderRequest $request, SaleOrder $order): RedirectResponse
    {
        $fulfillment = $this->fulfillments->fulfill($order, $request->user(), $request->validated());
        $this->toast("Atendimento #{$fulfillment->number} registrado.");

        return back();
    }

    public function reverse(SaleReasonRequest $request, SaleFulfillment $fulfillment): RedirectResponse
    {
        $this->fulfillments->reverse($fulfillment, $request->user(), $request->validated('reason'));
        $this->toast("Atendimento #{$fulfillment->number} estornado.");

        return back();
    }
}

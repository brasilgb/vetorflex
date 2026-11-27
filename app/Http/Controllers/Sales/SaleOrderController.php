<?php

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\SaleReasonRequest;
use App\Http\Requests\Sales\SaveSaleOrderRequest;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Payments\Services\ChargeDirectory;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Services\QuickSaleService;
use App\Modules\Sales\Services\SaleOrderService;
use App\Modules\Sales\Services\SaleSearch;
use App\Modules\Sales\Services\SalesStock;
use App\Modules\Sales\Support\SaleCsv;
use App\Modules\Sales\Support\SalePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaleOrderController extends SalesController
{
    public function __construct(
        private SaleOrderService $orders,
        private SaleSearch $search,
        private SalePresenter $presenter,
        private SaleFormOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('sales/orders/index', [
            'orders' => $this->search->orders($company, $request)->orderByDesc('order_date')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (SaleOrder $order): array => $this->presenter->orderSummary($order)),
            'filters' => $request->only(SaleSearch::ORDER_FILTERS),
            'statuses' => SaleOrderStatus::options(),
            'origins' => SaleOrigin::options(),
            'methods' => PaymentMethod::options(),
            'sellers' => $this->sellers($company),
            'selectedCustomer' => $this->options->selectedCustomer($company, $request->integer('customer')),
            'selectedProduct' => $this->options->selectedProduct($company, $request->integer('product')),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('sales/orders/form', [...$this->options->for($request), 'order' => null, 'quick' => $request->boolean('quick')]);
    }

    public function store(SaveSaleOrderRequest $request, QuickSaleService $quick): RedirectResponse
    {
        $company = $this->company($request);

        if ($request->boolean('quick')) {
            abort_unless($this->allows($request, 'sales.confirm') && $this->allows($request, 'sales.fulfill'), 403);
            $order = $quick->register($company, $request->user(), $request->validated(), $this->abilities($request));
            $this->toast("Venda #{$order->number} registrada e concluída.");

            return to_route('sales.orders.show', $order);
        }

        $order = $this->orders->create($company, $request->user(), $request->validated(), $this->abilities($request));
        $this->toast("Venda #{$order->number} salva como rascunho.");

        return to_route('sales.orders.show', $order);
    }

    public function show(Request $request, SaleOrder $order, SalesStock $stock): Response
    {
        $canViewCost = $this->canViewCost($request);
        $order->load(['items', 'paymentSchedules', 'statusHistory', 'quote']);

        return Inertia::render('sales/orders/show', [
            'order' => $this->presenter->order($order, $canViewCost, $this->canViewMargin($request)),
            'fulfillments' => $order->fulfillments()->with(['items.orderItem', 'returns'])->get()
                ->map(fn (SaleFulfillment $fulfillment): array => $this->presenter->fulfillment($fulfillment, $canViewCost))->values(),
            'returns' => $order->returns()->with(['fulfillment', 'items.fulfillmentItem.orderItem'])->get()
                ->map(fn (SaleReturn $return): array => $this->presenter->saleReturn($return, $canViewCost))->values(),
            'stockEnabled' => $stock->isEnabled($this->company($request)),
            'paymentCharges' => app(ChargeDirectory::class)->forOrigin($this->company($request), 'sale_order', $order->id, $this->allows($request, 'payments.view'), $this->allows($request, 'payments.create')),
            'today' => $this->today($request),
        ]);
    }

    public function edit(Request $request, SaleOrder $order): Response|RedirectResponse
    {
        if ($order->status !== SaleOrderStatus::Draft) {
            return to_route('sales.orders.show', $order);
        }

        $order->load(['items', 'paymentSchedules', 'statusHistory', 'quote', 'customer.addresses']);

        return Inertia::render('sales/orders/form', [
            ...$this->options->for($request, $order->customer),
            'order' => $this->presenter->order($order, false, false),
            'quick' => false,
        ]);
    }

    public function update(SaveSaleOrderRequest $request, SaleOrder $order): RedirectResponse
    {
        $this->orders->update($order, $request->user(), $request->validated(), $this->abilities($request));
        $this->toast("Venda #{$order->number} atualizada.");

        return to_route('sales.orders.show', $order);
    }

    public function confirm(Request $request, SaleOrder $order): RedirectResponse
    {
        $this->orders->confirm($order, $request->user());
        $this->toast("Venda #{$order->number} confirmada.");

        return back();
    }

    public function complete(Request $request, SaleOrder $order): RedirectResponse
    {
        $this->orders->complete($order, $request->user());
        $this->toast("Venda #{$order->number} concluída.");

        return back();
    }

    public function cancel(SaleReasonRequest $request, SaleOrder $order): RedirectResponse
    {
        $this->orders->cancel($order, $request->user(), $request->validated('reason'));
        $this->toast("Venda #{$order->number} cancelada.");

        return back();
    }

    public function closeBalance(SaleReasonRequest $request, SaleOrder $order): RedirectResponse
    {
        $this->orders->closeBalance($order, $request->user(), $request->validated('reason'));
        $this->toast("Saldo não atendido da venda #{$order->number} encerrado.");

        return back();
    }

    /** Uma linha por venda, protegida contra injeção de fórmulas. Custos e margens não são exportados. */
    public function export(Request $request, SaleCsv $csv, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $query = $this->search->orders($company, $request)->orderBy('id');
        $audit->record('sales.orders_exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, CsvFormat::BOM);
            $csv->write($output, ['numero', 'data', 'cliente', 'documento_cliente', 'situacao', 'origem', 'responsavel', 'total_itens', 'desconto', 'frete', 'outras_despesas', 'total', 'condicao_pagamento', 'forma_pagamento', 'referencia_externa']);

            foreach ($query->lazyById(200) as $order) {
                $csv->write($output, [
                    $order->number,
                    $order->order_date->format('d/m/Y'),
                    $order->customer_snapshot['name'] ?? 'Consumidor não identificado',
                    $order->customer_snapshot['document'] ?? null,
                    $order->status->label(),
                    $order->origin->label(),
                    $order->seller_name,
                    CsvFormat::formatDecimal($order->items_total),
                    CsvFormat::formatDecimal($order->discount),
                    CsvFormat::formatDecimal($order->freight),
                    CsvFormat::formatDecimal($order->other_costs),
                    CsvFormat::formatDecimal($order->total),
                    $order->payment_condition->label(),
                    $order->payment_method?->label(),
                    $order->external_reference,
                ]);
            }

            fclose($output);
        }, 'vendas-'.$this->today($request).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

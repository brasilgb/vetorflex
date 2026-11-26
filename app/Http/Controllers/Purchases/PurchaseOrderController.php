<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Requests\Purchases\PurchaseReasonRequest;
use App\Http\Requests\Purchases\SavePurchaseOrderRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Purchases\Enums\PaymentCondition;
use App\Modules\Purchases\Enums\PaymentMethod;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Services\PurchaseOrderSearch;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Purchases\Support\PurchasePresenter;
use App\Modules\Purchases\Support\SupplierCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends PurchaseController
{
    public function __construct(
        private PurchaseOrderService $orders,
        private PurchaseOrderSearch $search,
        private PurchasePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $canViewCost = $this->canViewCost($request);
        $today = $this->today($request);
        $supplier = $request->integer('supplier') > 0 ? SupplierProfile::query()->where('company_id', $company->id)->with('party')->find($request->integer('supplier')) : null;
        $product = $request->integer('product') > 0 ? ProductVariant::query()->where('company_id', $company->id)->with('product')->find($request->integer('product')) : null;

        return Inertia::render('purchases/orders/index', [
            'orders' => $this->search->query($company, $request)
                ->orderByDesc('order_date')->orderByDesc('id')
                ->paginate(15)->withQueryString()
                ->through(fn (PurchaseOrder $order): array => $this->presenter->orderSummary($order, $canViewCost, $today)),
            'filters' => $request->only(PurchaseOrderSearch::FILTERS),
            'statuses' => PurchaseOrderStatus::options(),
            'selectedSupplier' => $supplier === null ? null : ['id' => $supplier->id, 'name' => $supplier->party->name],
            'selectedProduct' => $product === null ? null : ['id' => $product->id, 'name' => $product->displayName()],
        ]);
    }

    public function create(Request $request): Response
    {
        $company = $this->company($request);
        $supplier = $request->integer('supplier') > 0 ? SupplierProfile::query()->where('company_id', $company->id)->where('is_active', true)->with('party')->find($request->integer('supplier')) : null;

        return Inertia::render('purchases/orders/form', [
            ...$this->formOptions($request),
            'order' => null,
            'selectedSupplier' => $supplier === null ? null : $this->presenter->supplierSummary($supplier),
        ]);
    }

    public function store(SavePurchaseOrderRequest $request): RedirectResponse
    {
        $order = $this->orders->create($this->company($request), $request->user(), $request->validated(), $this->canManageCost($request));
        $this->toast("Pedido #{$order->number} salvo como rascunho.");

        return to_route('purchases.orders.show', $order);
    }

    public function show(Request $request, PurchaseOrder $order): Response
    {
        $canViewCost = $this->canViewCost($request);
        $order->load(['items', 'paymentSchedules', 'statusHistory', 'supplier.party']);

        return Inertia::render('purchases/orders/show', [
            'order' => $this->presenter->order($order, $canViewCost, $this->today($request)),
            'supplier' => $this->presenter->supplierSummary($order->supplier),
            'receipts' => $order->receipts()->with('items.orderItem')->get()
                ->map(fn (PurchaseReceipt $receipt): array => $this->presenter->receipt($receipt, $canViewCost))->values(),
            'returns' => $order->returns()->with(['receipt', 'items.receiptItem.orderItem'])->get()
                ->map(fn (PurchaseReturn $return): array => $this->presenter->purchaseReturn($return, $canViewCost))->values(),
            'today' => $this->today($request),
        ]);
    }

    public function edit(Request $request, PurchaseOrder $order): Response|RedirectResponse
    {
        if ($order->status !== PurchaseOrderStatus::Draft) {
            return to_route('purchases.orders.show', $order);
        }

        $order->load(['items', 'paymentSchedules', 'statusHistory', 'supplier.party']);

        return Inertia::render('purchases/orders/form', [
            ...$this->formOptions($request),
            'order' => $this->presenter->order($order, $this->canViewCost($request), $this->today($request)),
            'selectedSupplier' => $this->presenter->supplierSummary($order->supplier),
        ]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $order): RedirectResponse
    {
        $this->orders->update($order, $request->user(), $request->validated(), $this->canManageCost($request));
        $this->toast("Pedido #{$order->number} atualizado.");

        return to_route('purchases.orders.show', $order);
    }

    public function confirm(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->orders->confirm($order, $request->user());
        $this->toast("Pedido #{$order->number} confirmado.");

        return back();
    }

    public function cancel(PurchaseReasonRequest $request, PurchaseOrder $order): RedirectResponse
    {
        $this->orders->cancel($order, $request->user(), $request->validated('reason'));
        $this->toast("Pedido #{$order->number} cancelado.");

        return back();
    }

    public function closeBalance(PurchaseReasonRequest $request, PurchaseOrder $order): RedirectResponse
    {
        $this->orders->closeBalance($order, $request->user(), $request->validated('reason'));
        $this->toast("Saldo pendente do pedido #{$order->number} encerrado.");

        return back();
    }

    /** Uma linha por pedido. Valores só são incluídos para quem pode consultar custos. */
    public function export(Request $request, SupplierCsv $csv, AuditService $audit): StreamedResponse
    {
        $company = $this->company($request);
        $canViewCost = $this->canViewCost($request);
        $query = $this->search->query($company, $request)->orderBy('id');
        $audit->record('purchase.orders_exported', $company, $request->user());

        return response()->streamDownload(function () use ($query, $csv, $canViewCost): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, CsvFormat::BOM);
            $csv->write($output, ['numero', 'data', 'previsao_entrega', 'fornecedor', 'documento_fornecedor', 'situacao', 'referencia_externa', 'responsavel', ...($canViewCost ? ['total_itens', 'desconto', 'frete', 'seguro', 'outras_despesas', 'total', 'condicao_pagamento'] : [])]);

            foreach ($query->lazyById(200) as $order) {
                $csv->write($output, [
                    $order->number,
                    $order->order_date->format('d/m/Y'),
                    $order->expected_date?->format('d/m/Y'),
                    $order->supplier_snapshot['name'] ?? null,
                    $order->supplier_snapshot['document'] ?? null,
                    $order->status->label(),
                    $order->external_reference,
                    $order->user_name,
                    ...($canViewCost ? [
                        CsvFormat::formatDecimal($order->items_total),
                        CsvFormat::formatDecimal($order->discount),
                        CsvFormat::formatDecimal($order->freight),
                        CsvFormat::formatDecimal($order->insurance),
                        CsvFormat::formatDecimal($order->other_costs),
                        CsvFormat::formatDecimal($order->total),
                        $order->payment_condition->label(),
                    ] : []),
                ]);
            }

            fclose($output);
        }, 'pedidos-compra-'.$this->today($request).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Busca de itens para o pedido, sem exigir acesso ao módulo Produtos. O código do fornecedor também é aceito.
     */
    public function lookup(Request $request, ProductCatalog $catalog): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100'], 'supplier' => ['nullable', 'integer']]);
        $company = $this->company($request);
        $supplierId = isset($validated['supplier']) ? (int) $validated['supplier'] : null;
        $bySupplierCode = $supplierId === null ? null : SupplierProduct::query()->where('company_id', $company->id)
            ->where('supplier_profile_id', $supplierId)->where('supplier_code', trim($validated['term']))->value('product_variant_id');
        $variants = $bySupplierCode !== null
            ? $catalog->sellableUnits($company)->whereKey($bySupplierCode)->get()
            : $catalog->searchSellableUnits($company, $validated['term']);
        $links = $supplierId === null ? collect() : SupplierProduct::query()->where('supplier_profile_id', $supplierId)
            ->whereIn('product_variant_id', $variants->modelKeys())->get()->keyBy('product_variant_id');
        $canViewCost = $this->canViewCost($request);

        return response()->json($variants->map(fn (ProductVariant $variant): array => [
            'id' => $variant->id,
            'name' => $variant->displayName(),
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'unit' => $variant->product->unit->only(['code', 'allows_fraction']),
            'controls_stock' => $variant->product->controlsStock(),
            'is_service' => $variant->product->type->value === 'service',
            'supplier_code' => $links->get($variant->id)?->supplier_code,
            'last_cost' => $canViewCost ? $links->get($variant->id)?->last_cost : null,
        ])->values());
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'conditions' => PaymentCondition::options(),
            'methods' => PaymentMethod::options(),
            'canManageCost' => $this->canManageCost($request),
            'today' => $this->today($request),
        ];
    }
}

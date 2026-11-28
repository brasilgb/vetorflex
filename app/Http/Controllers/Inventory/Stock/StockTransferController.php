<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockItems;
use App\Modules\Inventory\Support\StockPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transferências entre depósitos da empresa. Não alteram o saldo total nem o custo médio.
 */
class StockTransferController extends StockController
{
    public function index(Request $request, StockPresenter $presenter): Response
    {
        $company = $this->company($request);

        return Inertia::render('inventory/transfers', [
            'transfers' => InventoryTransfer::query()->where('company_id', $company->id)->with(['from', 'to', 'movements.variant.product.unit'])->latest('id')->paginate(20)
                ->through(fn (InventoryTransfer $transfer): array => $presenter->transfer($transfer)),
            'warehouses' => $this->warehouseOptions($company),
        ]);
    }

    public function store(Request $request, InventoryService $inventory, StockItems $items): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999999'],
            'reason' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['to_warehouse_id.different' => 'Escolha depósitos de origem e destino diferentes.', 'items.required' => 'Informe ao menos um item.', 'items.*.product_variant_id.distinct' => 'Cada mercadoria só pode aparecer uma vez.']);
        $ids = array_map(fn (array $item): int => (int) $item['product_variant_id'], $validated['items']);

        if ($items->query($company)->whereIn('product_variants.id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Selecione mercadorias desta empresa com controle de estoque.']);
        }

        $transfer = $inventory->transfer($company, (int) $validated['from_warehouse_id'], (int) $validated['to_warehouse_id'], array_combine($ids, array_map(fn (array $item): string => (string) $item['quantity'], $validated['items'])), $request->user(), $validated['reason'] ?? null, $validated['idempotency_key']);
        $this->toast("Transferência #{$transfer->number} registrada.");

        return back();
    }
}

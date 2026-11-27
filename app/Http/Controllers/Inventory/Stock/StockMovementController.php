<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockItems;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\Inventory\Support\StockPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Histórico de movimentações e lançamentos manuais (saldo inicial, entrada, saída e ajuste) por depósito. Entradas e
 * saídas exigem `inventory.move`; ajuste, saldo inicial e estorno exigem `inventory.adjust`. Correções são estornos,
 * nunca exclusões; movimentações de Compras, Vendas e inventários são corrigidas na origem.
 */
class StockMovementController extends StockController
{
    public function __construct(private InventoryService $inventory, private StockPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:20'],
            'origin' => ['nullable', 'string', 'max:20'],
            'warehouse' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);

        return Inertia::render('inventory/movements', [
            'movements' => InventoryMovement::query()->where('company_id', $company->id)->with(['variant.product.unit', 'warehouse', 'reversal'])
                ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->whereHas('variant', fn (Builder $variant) => $variant->where(fn (Builder $variant) => $variant
                    ->where('sku', 'like', "%{$search}%")->orWhere('barcode', Str::upper($search))->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")))))
                ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
                ->when($filters['origin'] ?? null, fn (Builder $query, string $origin) => $query->where('origin', $origin))
                ->when($filters['warehouse'] ?? null, fn (Builder $query, int $warehouse) => $query->where('inventory_warehouse_id', $warehouse))
                ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', Date::parse($date, $company->timezone)->startOfDay()->utc()))
                ->when($filters['until'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', Date::parse($date, $company->timezone)->endOfDay()->utc()))
                ->latest('occurred_at')->latest('id')->paginate(30)->withQueryString()
                ->through(fn (InventoryMovement $movement): array => $this->presenter->movement($movement, $canViewCost)),
            'filters' => [...$filters, 'warehouse' => isset($filters['warehouse']) ? (int) $filters['warehouse'] : null],
            'types' => MovementType::options(),
            'origins' => array_map(fn (MovementOrigin $origin): array => ['value' => $origin->value, 'label' => $origin->label()], MovementOrigin::cases()),
            'warehouses' => $this->warehouseOptions($company),
            'canMove' => $this->can($request, InventoryPermission::Move),
            'canAdjust' => $this->can($request, InventoryPermission::Adjust),
        ]);
    }

    public function store(Request $request, StockItems $items, StockModule $module): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'type' => ['required', 'in:initial,entry,exit,adjustment'],
            'product_variant_id' => ['required', 'integer'],
            'inventory_warehouse_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'unit_cost' => ['nullable', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'reason' => [in_array($request->input('type'), ['exit', 'adjustment'], true) ? 'required' : 'nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['reason.required' => 'Informe a justificativa.', 'quantity.required' => 'Informe a quantidade.']);
        $type = MovementType::from($validated['type']);
        abort_unless($this->can($request, in_array($type, [MovementType::Entry, MovementType::Exit], true) ? InventoryPermission::Move : InventoryPermission::Adjust), 403);
        $variant = $items->query($company)->findOrFail((int) $validated['product_variant_id']);
        $operation = new StockOperation(
            companyId: $company->id,
            variantId: $variant->id,
            quantity: (string) $validated['quantity'],
            origin: MovementOrigin::Manual,
            user: $request->user(),
            reason: $validated['reason'] ?? null,
            idempotencyKey: "inventory-manual:{$validated['idempotency_key']}",
            unitCost: $this->can($request, InventoryPermission::ViewCost) ? ($validated['unit_cost'] ?? null) : null,
            warehouseId: isset($validated['inventory_warehouse_id']) ? (int) $validated['inventory_warehouse_id'] : null,
        );
        match ($type) {
            MovementType::Initial => $this->inventory->recordInitial($operation),
            MovementType::Entry => $this->inventory->recordEntry($operation),
            MovementType::Exit => $this->inventory->recordExit($operation),
            default => $this->inventory->adjustTo($operation),
        };
        $module->ensureStarted($company, $request->user());
        $this->toast("{$type->label()} registrada.");

        return back();
    }

    public function reverse(Request $request, InventoryMovement $movement): RedirectResponse
    {
        $company = $this->company($request);
        abort_unless($movement->company_id === $company->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Informe o motivo do estorno.']);
        abort_if(in_array($movement->origin, [MovementOrigin::Purchase, MovementOrigin::Sale, MovementOrigin::Count], true), 422, 'Corrija esta movimentação no documento de origem.');
        $this->inventory->reverse($movement, $request->user(), $validated['reason'], "inventory-reversal:{$movement->id}");
        $this->toast('Movimentação estornada.');

        return back();
    }
}

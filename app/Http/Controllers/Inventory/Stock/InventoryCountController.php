<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryCountItem;
use App\Modules\Inventory\Services\InventoryCountService;
use App\Modules\Inventory\Services\StockItems;
use App\Modules\Inventory\Support\StockPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inventários: criação por depósito, registro das contagens, comparação com o saldo, aprovação e cancelamento.
 */
class InventoryCountController extends StockController
{
    public function __construct(private InventoryCountService $counts, private StockPresenter $presenter) {}

    public function index(Request $request, StockItems $items): Response
    {
        $company = $this->company($request);

        return Inertia::render('inventory/counts/index', [
            'counts' => InventoryCount::query()->where('company_id', $company->id)->with('warehouse')
                ->withCount(['items', 'items as counted_count' => fn ($query) => $query->whereNotNull('counted_quantity')])
                ->latest('id')->paginate(20)->through(fn (InventoryCount $count): array => $this->presenter->count($count)),
            'warehouses' => $this->warehouseOptions($company),
            'items' => $items->query($company)->where('product_variants.is_active', true)->orderBy('product_variants.id')->limit(500)->get()
                ->map(fn ($variant): array => ['id' => $variant->id, 'name' => $variant->displayName(), 'sku' => $variant->sku])->values()->all(),
            'canCount' => $this->can($request, InventoryPermission::Count),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'inventory_warehouse_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:160'],
            'items' => ['nullable', 'array', 'max:2000'],
            'items.*' => ['integer'],
        ], ['inventory_warehouse_id.required' => 'Escolha o depósito.']);
        $count = $this->counts->create($this->company($request), $request->user(), (int) $validated['inventory_warehouse_id'], isset($validated['items']) ? array_values(array_map('intval', $validated['items'])) : null, $validated['description'] ?? null);
        $this->toast("Inventário #{$count->number} criado.");

        return to_route('inventory.counts.show', $count);
    }

    public function show(Request $request, InventoryCount $count): Response
    {
        abort_unless($count->company_id === $this->company($request)->id, 404);
        $system = $count->status === CountStatus::Open ? $this->counts->systemQuantities($count) : [];

        return Inertia::render('inventory/counts/show', [
            'count' => $this->presenter->count($count->load('warehouse')),
            'items' => $count->items()->with('variant.product.unit')->orderBy('id')->get()->map(fn (InventoryCountItem $item): array => [
                'id' => $item->id,
                'name' => $item->variant->displayName(),
                'sku' => $item->variant->sku,
                'unit' => ['code' => $item->variant->product->unit->code, 'allows_fraction' => $item->variant->product->unit->allows_fraction],
                'counted_quantity' => $item->counted_quantity,
                'system_quantity' => $count->status === CountStatus::Open ? (string) ($system[$item->product_variant_id] ?? '0') : $item->system_quantity,
                'difference' => $item->difference,
                'adjusted' => $item->inventory_movement_id !== null,
            ])->values()->all(),
            'canCount' => $count->status === CountStatus::Open && $this->can($request, InventoryPermission::Count),
            'canApprove' => $count->status === CountStatus::Open && $this->can($request, InventoryPermission::ApproveCounts),
        ]);
    }

    public function update(Request $request, InventoryCount $count): RedirectResponse
    {
        abort_unless($count->company_id === $this->company($request)->id, 404);
        $validated = $request->validate(['counts' => ['required', 'array', 'max:2000'], 'counts.*' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999']]);
        $this->counts->record($count, $request->user(), $validated['counts']);
        $this->toast('Contagens salvas.');

        return back();
    }

    public function approve(Request $request, InventoryCount $count): RedirectResponse
    {
        abort_unless($count->company_id === $this->company($request)->id, 404);
        $count = $this->counts->approve($count, $request->user());
        $this->toast("Inventário #{$count->number} aprovado e ajustes gerados.");

        return back();
    }

    public function cancel(Request $request, InventoryCount $count): RedirectResponse
    {
        abort_unless($count->company_id === $this->company($request)->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:400']], ['reason.required' => 'Informe o motivo do cancelamento.']);
        $this->counts->cancel($count, $request->user(), $validated['reason']);
        $this->toast('Inventário cancelado, sem ajustes.');

        return back();
    }
}

<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Inventory\Services\StockReports;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Inventory\Support\StockPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Depósitos e localizações. O principal existe sempre e não pode ser inativado.
 */
class WarehouseController extends StockController
{
    public function __construct(private WarehouseService $warehouses, private StockPresenter $presenter) {}

    public function index(Request $request, StockReports $reports): Response
    {
        $company = $this->company($request);
        $this->warehouses->defaultFor($company->id);
        $distribution = collect($reports->warehouseDistribution($company))->keyBy('id');

        return Inertia::render('inventory/warehouses', [
            'warehouses' => InventoryWarehouse::query()->where('company_id', $company->id)->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (InventoryWarehouse $warehouse): array => [...$this->presenter->warehouse($warehouse), 'items' => $distribution[$warehouse->id]['items'] ?? 0, 'quantity' => $distribution[$warehouse->id]['quantity'] ?? '0'])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $warehouse = $this->warehouses->save($this->company($request), $request->user(), new InventoryWarehouse, $this->validated($request, null));
        $this->toast("Depósito {$warehouse->name} cadastrado.");

        return back();
    }

    public function update(Request $request, InventoryWarehouse $warehouse): RedirectResponse
    {
        abort_unless($warehouse->company_id === $this->company($request)->id, 404);
        $this->warehouses->save($this->company($request), $request->user(), $warehouse, $this->validated($request, $warehouse));
        $this->toast("Depósito {$warehouse->name} atualizado.");

        return back();
    }

    /** @return array{name: string, code: string, location?: string|null, is_active?: bool} */
    private function validated(Request $request, ?InventoryWarehouse $warehouse): array
    {
        $request->merge(['code' => mb_strtoupper((string) $request->input('code'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('inventory_warehouses', 'code')->where('company_id', $this->company($request)->id)->ignore($warehouse?->id)],
            'location' => ['nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Informe o nome do depósito.', 'code.required' => 'Informe o código.', 'code.unique' => 'Já existe um depósito com este código.', 'code.alpha_dash' => 'Use letras, números, hífen ou sublinhado no código.']);
    }
}

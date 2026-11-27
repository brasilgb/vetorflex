<?php

namespace App\Http\Controllers\Inventory\Stock;

use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Inventory\Models\InventoryWarehouseBalance;
use App\Modules\Inventory\Services\StockImportService;
use App\Modules\Inventory\Services\StockItems;
use App\Modules\Inventory\Support\StockCsv;
use App\Modules\Inventory\Support\StockPresenter;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\UnitOfMeasureProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mercadorias: consulta com saldos, cadastro simplificado, estoque mínimo e máximo, ficha individual e CSV.
 */
class StockItemController extends StockController
{
    public function __construct(private StockItems $items, private StockPresenter $presenter) {}

    public function index(Request $request, UnitOfMeasureProvisioner $units): Response
    {
        $company = $this->company($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'situation' => ['nullable', 'in:low,above_max,zero,with_balance,inactive'],
            'warehouse' => ['nullable', 'integer'],
        ]);
        $filters['warehouse'] = isset($filters['warehouse']) && InventoryWarehouse::query()->where('company_id', $company->id)->whereKey($filters['warehouse'])->exists() ? (int) $filters['warehouse'] : null;
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);
        $page = $this->items->query($company, $filters)->orderBy('product_variants.id', 'desc')->paginate(25)->withQueryString();
        $last = $this->items->lastMovementDates($company->id, array_values(array_map('intval', $page->getCollection()->modelKeys())));
        $units->ensureDefaults($company);

        return Inertia::render('inventory/items/index', [
            'items' => $page->through(fn (ProductVariant $variant): array => $this->presenter->item($variant, $canViewCost, $last[$variant->id] ?? null)),
            'filters' => [...$filters, 'category' => isset($filters['category']) ? (int) $filters['category'] : null],
            'categories' => ProductCategory::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name])->values()->all(),
            'units' => UnitOfMeasure::query()->where('company_id', $company->id)->where('is_active', true)->where('code', '!=', 'SERV')->orderBy('code')->get(['code', 'name', 'allows_fraction'])->values()->all(),
            'warehouses' => $this->warehouseOptions($company),
            'canManage' => $this->can($request, InventoryPermission::ManageItems),
            'canImportExport' => $this->can($request, InventoryPermission::ImportExport),
            'canViewCost' => $canViewCost,
        ]);
    }

    /** Busca de mercadorias para movimentações, transferências e inventários (código exato ou parte do nome/SKU). */
    public function lookup(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $validated = $request->validate(['term' => ['required', 'string', 'max:100'], 'warehouse' => ['nullable', 'integer']]);
        $warehouse = isset($validated['warehouse']) && InventoryWarehouse::query()->where('company_id', $company->id)->whereKey($validated['warehouse'])->exists() ? (int) $validated['warehouse'] : null;
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);
        $term = trim($validated['term']);
        $exact = $this->items->query($company, ['warehouse' => $warehouse])->where(fn ($query) => $query->where('product_variants.barcode', mb_strtoupper($term))->orWhere('product_variants.sku', mb_strtoupper($term)))->limit(1)->get();
        $found = $exact->isNotEmpty() ? $exact : $this->items->query($company, ['search' => $term, 'warehouse' => $warehouse])->orderBy('product_variants.id')->limit(15)->get();

        return response()->json($found->map(fn (ProductVariant $variant): array => $this->presenter->item($variant, $canViewCost))->values());
    }

    public function show(Request $request, ProductVariant $variant): Response
    {
        $company = $this->company($request);
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);
        $item = $this->items->query($company)->findOrFail($variant->id);
        $balances = InventoryWarehouseBalance::query()->where('product_variant_id', $item->id)->pluck('quantity', 'inventory_warehouse_id');

        return Inertia::render('inventory/items/show', [
            'item' => [...$this->presenter->item($item, $canViewCost, $this->items->lastMovementDates($company->id, [$item->id])[$item->id] ?? null), 'average_cost' => $canViewCost ? InventoryBalance::query()->where('product_variant_id', $item->id)->value('average_cost') : null],
            'byWarehouse' => array_values(InventoryWarehouse::query()->where('company_id', $company->id)->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (InventoryWarehouse $warehouse): array => ['id' => $warehouse->id, 'name' => $warehouse->name, 'is_active' => $warehouse->is_active, 'quantity' => (string) ($balances[$warehouse->id] ?? '0')])
                ->filter(fn (array $row): bool => $row['is_active'] || $row['quantity'] !== '0' && $row['quantity'] !== '0.0000')->values()->all()),
            'movements' => InventoryMovement::query()->where('company_id', $company->id)->where('product_variant_id', $item->id)->with(['variant.product.unit', 'warehouse', 'reversal'])
                ->latest('occurred_at')->latest('id')->limit(50)->get()->map(fn (InventoryMovement $movement): array => $this->presenter->movement($movement, $canViewCost))->values()->all(),
            'canManage' => $this->can($request, InventoryPermission::ManageItems),
            'canMove' => $this->can($request, InventoryPermission::Move),
            'canAdjust' => $this->can($request, InventoryPermission::Adjust),
            'canViewCost' => $canViewCost,
            'warehouses' => $this->warehouseOptions($company),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('product_variants', 'sku')->where('company_id', $company->id)],
            'barcode' => ['nullable', 'string', 'max:60', Rule::unique('product_variants', 'barcode')->where('company_id', $company->id)],
            'internal_code' => ['nullable', 'string', 'max:60', Rule::unique('products', 'internal_code')->where('company_id', $company->id)],
            'product_category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:80'],
            'unit_code' => ['nullable', 'string', 'max:10'],
            'minimum_stock' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'maximum_stock' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'cost_price' => [$this->can($request, InventoryPermission::ViewCost) ? 'nullable' : 'prohibited', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'sale_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'initial_stock' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'inventory_warehouse_id' => ['nullable', 'integer'],
        ], ['name.required' => 'Informe o nome da mercadoria.', 'sku.unique' => 'Este SKU já está cadastrado.', 'barcode.unique' => 'Este código de barras já está cadastrado.', 'internal_code.unique' => 'Este código interno já está cadastrado.']);
        $variant = $this->items->create($company, $request->user(), $validated);
        $this->toast("Mercadoria {$variant->displayName()} cadastrada.");

        return back();
    }

    public function thresholds(Request $request, ProductVariant $variant): RedirectResponse
    {
        $company = $this->company($request);
        $item = $this->items->query($company)->findOrFail($variant->id);
        $validated = $request->validate([
            'minimum_stock' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'maximum_stock' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
        ]);
        $this->items->updateThresholds(ProductVariant::query()->findOrFail($item->id), $request->user(), (string) $validated['minimum_stock'], isset($validated['maximum_stock']) ? (string) $validated['maximum_stock'] : null);
        $this->toast('Estoque mínimo e máximo atualizados.');

        return back();
    }

    public function export(Request $request, StockCsv $csv): StreamedResponse
    {
        $company = $this->company($request);
        $canViewCost = $this->can($request, InventoryPermission::ViewCost);
        $query = $this->items->query($company);

        return response()->streamDownload(function () use ($query, $csv, $canViewCost): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, StockCsv::BOM);
            $csv->write($output, ['nome', 'sku', 'codigo_barras', 'codigo_interno', 'categoria', 'unidade', 'estoque_minimo', 'estoque_maximo', 'custo', 'saldo', 'reservado', 'situacao']);
            $query->orderBy('product_variants.id')->chunk(500, function ($variants) use ($output, $csv, $canViewCost): void {
                foreach ($variants as $variant) {
                    $csv->write($output, [
                        $variant->displayName(), $variant->sku, $variant->barcode, $variant->product->internal_code, $variant->product->category?->name, $variant->product->unit->code,
                        StockCsv::formatDecimal($variant->minimum_stock), StockCsv::formatDecimal($variant->maximum_stock), $canViewCost ? StockCsv::formatDecimal($variant->cost_price) : null,
                        StockCsv::formatDecimal((string) $variant->getAttribute('balance')), StockCsv::formatDecimal((string) $variant->getAttribute('reserved')),
                        $variant->is_active && $variant->product->is_active ? 'ativa' : 'inativa',
                    ]);
                }
            });
            fclose($output);
        }, 'estoque-mercadorias.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(StockCsv $csv): StreamedResponse
    {
        return response()->streamDownload(function () use ($csv): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, StockCsv::BOM);
            $csv->write($output, StockCsv::COLUMNS);
            $csv->write($output, ['Caderno 96 folhas', 'CAD-96', '', '', 'Papelaria', 'UN', '5', '50', '8,50', '15,90', '20', 'PRINCIPAL']);
            fclose($output);
        }, 'modelo-estoque.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request, StockImportService $imports): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], ['file.required' => 'Selecione o arquivo CSV.']);
        $result = $imports->import($this->company($request), $request->user(), $request->file('file'));
        $this->toast("Importação concluída: {$result['created']} mercadoria(s) cadastrada(s), ".count($result['skipped']).' ignorada(s), '.count($result['errors']).' com erro.');

        Inertia::flash('importResult', $result);

        return back();
    }
}

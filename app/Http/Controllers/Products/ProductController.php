<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Enums\BarcodeType;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductPriceChange;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\CategoryService;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Products\Services\ProductIndicators;
use App\Modules\Products\Services\ProductSearch;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Services\UnitOfMeasureProvisioner;
use App\Modules\Products\Support\ProductPresenter;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use InteractsWithCompany;

    private const RECENT_RECORDS = 20;

    public function __construct(
        private ProductService $products,
        private ProductPresenter $presenter,
        private CategoryService $categories,
        private UnitOfMeasureProvisioner $units,
        private ProductSearch $search,
    ) {}

    public function index(Request $request, ProductIndicators $indicators): Response
    {
        $company = $this->company($request);
        $canViewInventory = $this->allows($request, ProductPermission::ViewInventory->value);

        $products = $this->search->query($company, $request, $canViewInventory)
            ->with(['category', 'brand', 'unit', 'variants.balance', 'images'])
            ->orderBy('name')->orderBy('id')
            ->paginate(15)->withQueryString()
            ->through(function (Product $product) use ($request): array {
                $primary = $product->images->firstWhere('is_primary', true);

                return [
                    ...$this->presenter->product($product),
                    'image_url' => $primary === null ? null : $this->presenter->image($primary)['url'],
                    'variants' => $product->variants->map(fn (ProductVariant $variant): array => $this->presenter->variant($request, $variant, $product))->values(),
                ];
            });

        return Inertia::render('products/index', [
            'products' => $products,
            'filters' => $request->only(ProductSearch::FILTERS),
            'indicators' => $indicators->for($company, $canViewInventory, $this->allows($request, ProductPermission::ViewCost->value)),
            'types' => ProductType::options(),
            'categories' => $this->categories->options($company),
            'brands' => ProductBrand::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('products/create', $this->formOptions($this->company($request)));
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($this->company($request), $request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produto cadastrado com sucesso.']);

        return to_route('products.show', $product);
    }

    public function show(Request $request, Product $product): Response
    {
        $product->load(['category', 'brand', 'unit', 'variants.balance', 'images']);
        $variantIds = $product->variants->pluck('id');
        $priceFields = $this->allows($request, ProductPermission::ViewCost->value) ? ProductPriceChange::FIELDS : array_values(array_diff(ProductPriceChange::FIELDS, ['cost_price']));

        return Inertia::render('products/show', [
            'product' => $this->presenter->product($product),
            'variants' => $product->variants->sortBy('id')->map(fn (ProductVariant $variant): array => $this->presenter->variant($request, $variant, $product))->values(),
            'images' => $product->images->map($this->presenter->image(...))->values(),
            'priceChanges' => ProductPriceChange::query()
                ->whereIn('product_variant_id', $variantIds)->whereIn('field', $priceFields)->with('variant')
                ->latest('changed_at')->latest('id')->limit(self::RECENT_RECORDS)->get()
                ->map(fn (ProductPriceChange $change): array => [
                    ...$change->only(['id', 'field', 'old_value', 'new_value', 'user_name']),
                    'variant' => $change->variant->label,
                    'changed_at' => $change->changed_at->toIso8601String(),
                ]),
            'movements' => ! $this->allows($request, ProductPermission::ViewInventory->value) ? [] : InventoryMovement::query()
                ->whereIn('product_variant_id', $variantIds)->with(['variant.product.unit', 'reversal'])
                ->latest('occurred_at')->latest('id')->limit(self::RECENT_RECORDS)->get()
                ->map($this->presenter->movement(...)),
            'barcodeTypes' => BarcodeType::options(),
        ]);
    }

    public function edit(Request $request, Product $product): Response
    {
        $product->load(['category', 'brand', 'unit']);
        $variant = $product->variants()->where('is_default', true)->with('balance')->first();

        return Inertia::render('products/edit', [
            ...$this->formOptions($this->company($request), $product),
            'product' => $this->presenter->product($product),
            'variant' => $variant === null ? null : $this->presenter->variant($request, $variant, $product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produto atualizado com sucesso.']);

        return to_route('products.show', $product);
    }

    public function deactivate(Request $request, Product $product): RedirectResponse
    {
        return $this->setActive($request, $product, false);
    }

    public function reactivate(Request $request, Product $product): RedirectResponse
    {
        return $this->setActive($request, $product, true);
    }

    /**
     * Busca de unidades comercializáveis. Leitores de código de barras enviam o código seguido de Enter.
     */
    public function lookup(Request $request, ProductCatalog $catalog): JsonResponse
    {
        $validated = $request->validate(['term' => ['required', 'string', 'max:100']]);
        $canViewStock = $this->allows($request, ProductPermission::ViewInventory->value);

        return response()->json($catalog->searchSellableUnits($this->company($request), $validated['term'])
            ->load('balance')
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'name' => $variant->displayName(),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'unit' => $variant->product->unit->only(['code', 'allows_fraction']),
                'controls_stock' => $variant->product->controlsStock(),
                'balance' => $canViewStock && $variant->product->controlsStock() ? ($variant->balance->quantity ?? '0.0000') : null,
            ])->values());
    }

    private function setActive(Request $request, Product $product, bool $isActive): RedirectResponse
    {
        $this->products->setActive($product, $request->user(), $isActive);
        Inertia::flash('toast', ['type' => 'success', 'message' => $isActive ? 'Produto reativado.' : 'Produto inativado.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function formOptions(Company $company, ?Product $product = null): array
    {
        $this->units->ensureDefaults($company);

        return [
            'types' => ProductType::options(),
            'barcodeTypes' => BarcodeType::options(),
            'categories' => $this->categories->options($company, $product?->product_category_id),
            'brands' => ProductBrand::query()->where('company_id', $company->id)
                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $product?->product_brand_id))
                ->orderBy('name')->get(['id', 'name']),
            'units' => UnitOfMeasure::query()->where('company_id', $company->id)
                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $product?->unit_of_measure_id))
                ->orderBy('id')->get(['id', 'code', 'name', 'allows_fraction']),
        ];
    }
}

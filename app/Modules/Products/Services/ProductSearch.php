<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Support\Gtin;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Filtros do catálogo, comuns à listagem e à exportação.
 */
class ProductSearch
{
    public const FILTERS = ['search', 'type', 'category', 'brand', 'active', 'stock'];

    public function __construct(private CategoryService $categories) {}

    /** @return Builder<Product> */
    public function query(Company $company, Request $request, bool $canViewInventory): Builder
    {
        $categoryId = $request->integer('category');

        return Product::query()
            ->where('products.company_id', $company->id)
            ->when($request->string('search')->trim()->value(), fn (Builder $query, string $search) => $this->search($query, $search))
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->string('type')->value()))
            ->when($categoryId > 0, fn (Builder $query) => $query->whereIn('product_category_id', $this->categories->descendantIds($company, $categoryId)))
            ->when($request->filled('brand'), fn (Builder $query) => $query->where('product_brand_id', $request->integer('brand')))
            ->when($request->filled('active'), fn (Builder $query) => $query->where('is_active', $request->boolean('active')))
            ->when($canViewInventory, fn (Builder $query) => match ($request->string('stock')->value()) {
                'controlled' => $query->controllingStock(),
                'low' => $query->belowMinimumStock(),
                'out' => $query->outOfStock(),
                default => $query,
            });
    }

    /** @param Builder<Product> $query */
    private function search(Builder $query, string $search): void
    {
        $code = Str::upper($search);
        $codes = array_values(array_unique([$code, Gtin::normalize($code)]));

        $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$search}%")
            ->orWhere('internal_code', 'like', "%{$search}%")
            ->orWhere('manufacturer_reference', 'like', "%{$search}%")
            ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'like', "%{$code}%")->orWhereIn('barcode', $codes)));
    }
}

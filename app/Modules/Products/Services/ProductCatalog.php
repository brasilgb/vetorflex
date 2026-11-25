<?php

namespace App\Modules\Products\Services;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Support\Gtin;
use App\Modules\Products\Support\Pricing;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Contrato interno de leitura para Compras, Vendas, PDV e canais. Movimentações usam InventoryService.
 */
class ProductCatalog
{
    public function __construct(private InventoryService $inventory) {}

    /** @return Builder<Product> */
    public function activeProducts(Company $company): Builder
    {
        return Product::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->orderBy('id');
    }

    /** @return Builder<ProductVariant> */
    public function sellableUnits(Company $company): Builder
    {
        return ProductVariant::query()->where('product_variants.company_id', $company->id)->sellable()->with('product.unit');
    }

    /** @return Collection<int, ProductVariant> */
    public function searchSellableUnits(Company $company, string $term, int $limit = 10): Collection
    {
        $exact = $this->findByCode($company, $term);

        if ($exact !== null) {
            return new Collection([$exact]);
        }

        return $this->sellableUnits($company)
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', "%{$term}%")
                ->orWhere('label', 'like', "%{$term}%")
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$term}%")->orWhere('internal_code', $term)))
            ->orderBy('product_id')->orderBy('id')->limit($limit)->get();
    }

    /**
     * Busca exata para leitores de código de barras: código de barras primeiro, depois SKU.
     */
    public function findByCode(Company $company, string $code): ?ProductVariant
    {
        $code = Str::upper(trim($code));

        if ($code === '') {
            return null;
        }

        return $this->sellableUnits($company)->whereIn('barcode', array_unique([$code, Gtin::normalize($code)]))->first()
            ?? $this->sellableUnits($company)->where('sku', $code)->first();
    }

    /** @return array{sale: string|null, promotional: string|null, effective: string|null} */
    public function pricesOf(ProductVariant $variant): array
    {
        return [
            'sale' => $variant->sale_price,
            'promotional' => $variant->promotional_price,
            'effective' => Pricing::effective($variant->sale_price, $variant->promotional_price),
        ];
    }

    public function balanceOf(ProductVariant $variant): string
    {
        return $this->inventory->balanceOf($variant->company_id, $variant->id);
    }
}

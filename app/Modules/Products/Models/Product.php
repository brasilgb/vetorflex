<?php

namespace App\Modules\Products\Models;

use App\Modules\Products\Enums\ProductType;
use App\Modules\Tenancy\Models\Company;
use Database\Factories\Modules\Products\Models\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cadastro comercial. Preço, códigos e estoque pertencem à unidade comercializável (ProductVariant).
 *
 * @property int $id
 * @property int $company_id
 * @property ProductType $type
 * @property string $name
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $internal_code
 * @property int|null $product_category_id
 * @property int|null $product_brand_id
 * @property int $unit_of_measure_id
 * @property string|null $manufacturer_reference
 * @property bool $tracks_inventory
 * @property string|null $ncm
 * @property string|null $cest
 * @property string|null $merchandise_origin
 * @property string|null $notes
 * @property bool $is_active
 * @property-read UnitOfMeasure $unit
 * @property-read ProductCategory|null $category
 * @property-read ProductBrand|null $brand
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'type', 'name', 'short_description', 'description', 'internal_code', 'product_category_id', 'product_brand_id', 'unit_of_measure_id', 'manufacturer_reference', 'tracks_inventory', 'ncm', 'cest', 'merchandise_origin', 'notes', 'is_active', 'created_by'];

    protected $attributes = ['tracks_inventory' => true, 'is_active' => true];

    protected function casts(): array
    {
        return ['type' => ProductType::class, 'tracks_inventory' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /** @return BelongsTo<ProductBrand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'product_brand_id');
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function controlsStock(): bool
    {
        return $this->type !== ProductType::Service && $this->tracks_inventory;
    }

    /** @param Builder<Product> $query */
    #[Scope]
    protected function controllingStock(Builder $query): void
    {
        $query->where('type', '!=', ProductType::Service)->where('tracks_inventory', true);
    }

    /** @param Builder<Product> $query */
    #[Scope]
    protected function belowMinimumStock(Builder $query): void
    {
        $query->where('type', '!=', ProductType::Service)->where('tracks_inventory', true)->whereHas('variants', fn (Builder $variant) => $variant
            ->where('product_variants.is_active', true)
            ->where('minimum_stock', '>', 0)
            ->where(fn (Builder $variant) => $variant
                ->whereDoesntHave('balance')
                ->orWhereHas('balance', fn (Builder $balance) => $balance->whereColumn('inventory_balances.quantity', '<', 'product_variants.minimum_stock'))));
    }

    /**
     * Produto com unidades comercializáveis ativas, nenhuma delas com saldo.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function outOfStock(Builder $query): void
    {
        $query->where('type', '!=', ProductType::Service)->where('tracks_inventory', true)
            ->whereHas('variants', fn (Builder $variant) => $variant->where('product_variants.is_active', true))
            ->whereDoesntHave('variants', fn (Builder $variant) => $variant
                ->where('product_variants.is_active', true)
                ->whereHas('balance', fn (Builder $balance) => $balance->where('quantity', '>', 0)));
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}

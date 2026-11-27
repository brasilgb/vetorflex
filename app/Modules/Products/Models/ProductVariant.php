<?php

namespace App\Modules\Products\Models;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Enums\BarcodeType;
use Database\Factories\Modules\Products\Models\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Unidade comercializável. Compras, Vendas e Estoque referenciam sempre este registro.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property string|null $label
 * @property string|null $combination_key
 * @property string|null $sku
 * @property string|null $barcode
 * @property BarcodeType|null $barcode_type
 * @property string|null $cost_price
 * @property string|null $sale_price
 * @property string|null $promotional_price
 * @property string $minimum_stock
 * @property string|null $maximum_stock Estoque máximo (opcional), usado nos alertas do módulo Estoque.
 * @property string|null $weight
 * @property string|null $height
 * @property string|null $width
 * @property string|null $length
 * @property bool $is_default
 * @property bool $is_active
 * @property-read Product $product
 * @property-read InventoryBalance|null $balance
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'product_id', 'label', 'combination_key', 'sku', 'barcode', 'barcode_type', 'cost_price', 'sale_price', 'promotional_price', 'minimum_stock', 'maximum_stock', 'weight', 'height', 'width', 'length', 'is_default', 'is_active'];

    protected $attributes = ['minimum_stock' => '0', 'is_default' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'barcode_type' => BarcodeType::class,
            'cost_price' => 'decimal:4',
            'sale_price' => 'decimal:2',
            'promotional_price' => 'decimal:2',
            'minimum_stock' => 'decimal:4',
            'maximum_stock' => 'decimal:4',
            'weight' => 'decimal:4',
            'height' => 'decimal:4',
            'width' => 'decimal:4',
            'length' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<ProductAttributeValue, $this> */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'product_variant_attributes')->withPivot(['company_id', 'product_attribute_id'])->withTimestamps();
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /** @return HasMany<ProductPriceChange, $this> */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(ProductPriceChange::class);
    }

    /** @return HasOne<InventoryBalance, $this> */
    public function balance(): HasOne
    {
        return $this->hasOne(InventoryBalance::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function displayName(): string
    {
        return $this->label === null ? $this->product->name : "{$this->product->name} — {$this->label}";
    }

    /** @param Builder<ProductVariant> $query */
    #[Scope]
    protected function sellable(Builder $query): void
    {
        $query->where('product_variants.is_active', true)
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true));
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}

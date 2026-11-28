<?php

namespace App\Modules\Sales\Models;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item com cópia histórica de descrição, unidade e preço. Item avulso não tem unidade comercializável.
 *
 * @property int $id
 * @property int $company_id
 * @property int $sale_quote_id
 * @property int|null $product_variant_id
 * @property int $position
 * @property string $description
 * @property string|null $sku
 * @property string $unit_code
 * @property bool $allows_fraction
 * @property bool $is_service
 * @property bool $is_custom
 * @property bool $controls_stock
 * @property string $quantity
 * @property string|null $catalog_price
 * @property string $unit_price
 * @property string|null $discount_percent
 * @property string $discount
 * @property string $total
 * @property string|null $notes
 * @property-read ProductVariant|null $variant
 */
class SaleQuoteItem extends Model
{
    protected $fillable = ['company_id', 'sale_quote_id', 'product_variant_id', 'position', 'description', 'sku', 'unit_code', 'allows_fraction', 'is_service', 'is_custom', 'controls_stock', 'quantity', 'catalog_price', 'unit_price', 'discount_percent', 'discount', 'total', 'notes'];

    protected function casts(): array
    {
        return [
            'allows_fraction' => 'boolean',
            'is_service' => 'boolean',
            'is_custom' => 'boolean',
            'controls_stock' => 'boolean',
            'quantity' => 'decimal:4',
            'catalog_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

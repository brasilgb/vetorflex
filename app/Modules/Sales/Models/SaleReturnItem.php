<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sale_return_id
 * @property int $sale_fulfillment_item_id
 * @property int|null $product_variant_id
 * @property string $quantity
 * @property string $historical_value
 * @property string|null $cost_value
 * @property int|null $inventory_movement_id
 * @property-read SaleFulfillmentItem $fulfillmentItem
 */
class SaleReturnItem extends Model
{
    protected $fillable = ['company_id', 'sale_return_id', 'sale_fulfillment_item_id', 'product_variant_id', 'quantity', 'historical_value', 'cost_value', 'inventory_movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'historical_value' => 'decimal:2', 'cost_value' => 'decimal:2'];
    }

    /** @return BelongsTo<SaleFulfillmentItem, $this> */
    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(SaleFulfillmentItem::class, 'sale_fulfillment_item_id');
    }
}

<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $sale_fulfillment_id
 * @property int $sale_order_item_id
 * @property int|null $product_variant_id
 * @property string $quantity
 * @property string $revenue
 * @property string|null $unit_cost Custo médio vigente no atendimento; nulo quando não há custo confiável.
 * @property string|null $cost_total
 * @property string $returned_quantity
 * @property int|null $inventory_movement_id
 * @property-read SaleFulfillment $fulfillment
 * @property-read SaleOrderItem $orderItem
 * @property-read InventoryMovement|null $movement
 */
class SaleFulfillmentItem extends Model
{
    protected $fillable = ['company_id', 'sale_fulfillment_id', 'sale_order_item_id', 'product_variant_id', 'quantity', 'revenue', 'unit_cost', 'cost_total', 'returned_quantity', 'inventory_movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'revenue' => 'decimal:2', 'unit_cost' => 'decimal:6', 'cost_total' => 'decimal:2', 'returned_quantity' => 'decimal:4'];
    }

    /** @return BelongsTo<SaleFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(SaleFulfillment::class, 'sale_fulfillment_id');
    }

    /** @return BelongsTo<SaleOrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(SaleOrderItem::class, 'sale_order_item_id');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}

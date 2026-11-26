<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `unit_cost` é o custo efetivo da aquisição; `purchase_unit_cost` é o preço negociado, líquido do desconto do item.
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_receipt_id
 * @property int $purchase_order_item_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $acquisition_total
 * @property string $unit_cost
 * @property string $purchase_unit_cost
 * @property string $returned_quantity
 * @property string|null $divergence
 * @property int|null $inventory_movement_id
 * @property-read PurchaseOrderItem $orderItem
 * @property-read PurchaseReceipt $receipt
 * @property-read ProductVariant $variant
 * @property-read InventoryMovement|null $movement
 */
class PurchaseReceiptItem extends Model
{
    protected $fillable = ['company_id', 'purchase_receipt_id', 'purchase_order_item_id', 'product_variant_id', 'quantity', 'acquisition_total', 'unit_cost', 'purchase_unit_cost', 'returned_quantity', 'divergence', 'inventory_movement_id'];

    protected $attributes = ['returned_quantity' => '0'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'acquisition_total' => 'decimal:2', 'unit_cost' => 'decimal:6', 'purchase_unit_cost' => 'decimal:4', 'returned_quantity' => 'decimal:4'];
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    /** @return BelongsTo<PurchaseReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}

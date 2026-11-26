<?php

namespace App\Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_receipt_item_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $historical_value
 * @property string|null $stock_value
 * @property-read PurchaseReceiptItem $receiptItem
 */
class PurchaseReturnItem extends Model
{
    protected $fillable = ['company_id', 'purchase_return_id', 'purchase_receipt_item_id', 'product_variant_id', 'quantity', 'historical_value', 'stock_value', 'inventory_movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'historical_value' => 'decimal:2', 'stock_value' => 'decimal:2'];
    }

    /** @return BelongsTo<PurchaseReceiptItem, $this> */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptItem::class, 'purchase_receipt_item_id');
    }
}

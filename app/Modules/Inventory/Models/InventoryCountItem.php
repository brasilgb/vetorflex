<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de inventário: quantidade contada e, na aprovação, saldo do sistema, diferença e movimentação de ajuste.
 *
 * @property int $id
 * @property int $company_id
 * @property int $inventory_count_id
 * @property int $product_variant_id
 * @property string|null $counted_quantity
 * @property string|null $system_quantity
 * @property string|null $difference
 * @property int|null $inventory_movement_id
 * @property-read ProductVariant $variant
 */
class InventoryCountItem extends Model
{
    protected $fillable = ['company_id', 'inventory_count_id', 'product_variant_id', 'counted_quantity', 'system_quantity', 'difference', 'inventory_movement_id'];

    protected function casts(): array
    {
        return ['counted_quantity' => 'decimal:4', 'system_quantity' => 'decimal:4', 'difference' => 'decimal:4'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

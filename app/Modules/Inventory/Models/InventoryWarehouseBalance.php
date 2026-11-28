<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo por depósito. A soma dos depósitos é igual a `inventory_balances.quantity`. Somente InventoryService grava.
 *
 * @property int $id
 * @property int $company_id
 * @property int $inventory_warehouse_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property-read InventoryWarehouse $warehouse
 */
class InventoryWarehouseBalance extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    /** @return BelongsTo<InventoryWarehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'inventory_warehouse_id');
    }
}

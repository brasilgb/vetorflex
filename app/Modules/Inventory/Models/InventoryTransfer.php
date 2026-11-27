<?php

namespace App\Modules\Inventory\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transferência entre depósitos da mesma empresa: duas movimentações por item (saída e entrada), sem alterar o saldo
 * total nem o custo médio.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string|null $reason
 * @property string $idempotency_key
 * @property CarbonImmutable $transferred_at
 * @property-read InventoryWarehouse $from
 * @property-read InventoryWarehouse $to
 */
class InventoryTransfer extends Model
{
    protected $fillable = ['company_id', 'number', 'from_warehouse_id', 'to_warehouse_id', 'user_id', 'user_name', 'reason', 'idempotency_key', 'transferred_at'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    /** @return BelongsTo<InventoryWarehouse, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'from_warehouse_id');
    }

    /** @return BelongsTo<InventoryWarehouse, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'to_warehouse_id');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'reference_id')->where('reference_type', 'inventory_transfer');
    }
}

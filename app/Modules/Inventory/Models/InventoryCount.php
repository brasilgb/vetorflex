<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\CountStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventário (contagem física) de um depósito. Em contagem, aceita quantidades; aprovado, gera os ajustes e fica
 * somente para consulta.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $inventory_warehouse_id
 * @property CountStatus $status
 * @property string|null $description
 * @property string|null $created_by_name
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property string|null $cancelled_by_name
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $created_at
 * @property-read InventoryWarehouse $warehouse
 * @property-read Collection<int, InventoryCountItem> $items
 */
class InventoryCount extends Model
{
    protected $fillable = ['company_id', 'number', 'inventory_warehouse_id', 'status', 'description', 'created_by', 'created_by_name', 'approved_by', 'approved_by_name', 'approved_at', 'cancelled_by', 'cancelled_by_name', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['status' => CountStatus::class, 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<InventoryWarehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'inventory_warehouse_id');
    }

    /** @return HasMany<InventoryCountItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }
}

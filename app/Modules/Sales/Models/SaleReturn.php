<?php

namespace App\Modules\Sales\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolução comercial de itens atendidos. Preserva os valores históricos da venda.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $sale_order_id
 * @property int $sale_fulfillment_id
 * @property CarbonImmutable $returned_at
 * @property string $reason
 * @property bool $restock
 * @property string $total_value
 * @property string|null $cost_total
 * @property string|null $user_name
 * @property string $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable|null $created_at
 * @property-read SaleOrder $order
 * @property-read SaleFulfillment $fulfillment
 */
class SaleReturn extends Model
{
    protected $fillable = ['company_id', 'number', 'sale_order_id', 'sale_fulfillment_id', 'returned_at', 'reason', 'restock', 'total_value', 'cost_total', 'user_id', 'user_name', 'idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'returned_at' => 'date:Y-m-d', 'restock' => 'boolean', 'total_value' => 'decimal:2', 'cost_total' => 'decimal:2'];
    }

    /** @return BelongsTo<SaleOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class, 'sale_order_id');
    }

    /** @return BelongsTo<SaleFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(SaleFulfillment::class, 'sale_fulfillment_id');
    }

    /** @return HasMany<SaleReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class)->orderBy('id');
    }
}

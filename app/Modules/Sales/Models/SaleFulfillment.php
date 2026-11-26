<?php

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\FulfillmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Atendimento (retirada, entrega ou serviço), independente da situação financeira.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $sale_order_id
 * @property FulfillmentStatus $status
 * @property DeliveryMethod $method
 * @property CarbonImmutable $fulfilled_at
 * @property string|null $notes
 * @property string $revenue
 * @property string|null $cost_total Nulo quando algum item não tem custo confiável.
 * @property string|null $user_name
 * @property string $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_name
 * @property string|null $reversal_reason
 * @property CarbonImmutable|null $created_at
 * @property-read SaleOrder $order
 */
class SaleFulfillment extends Model
{
    protected $fillable = ['company_id', 'number', 'sale_order_id', 'status', 'method', 'fulfilled_at', 'notes', 'revenue', 'cost_total', 'user_id', 'user_name', 'idempotency_key', 'request_hash', 'reversed_at', 'reversed_by_name', 'reversal_reason'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => FulfillmentStatus::class,
            'method' => DeliveryMethod::class,
            'fulfilled_at' => 'date:Y-m-d',
            'revenue' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaleOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class, 'sale_order_id');
    }

    /** @return HasMany<SaleFulfillmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleFulfillmentItem::class)->orderBy('id');
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }
}

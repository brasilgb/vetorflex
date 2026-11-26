<?php

namespace App\Modules\Sales\Models;

use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido de venda. Totais são calculados no servidor; dados do cliente e dos itens são cópias históricas.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property SaleOrderStatus $status
 * @property SaleOrigin $origin
 * @property int|null $sale_quote_id
 * @property string|null $external_reference
 * @property int|null $customer_party_id
 * @property array<string, mixed>|null $customer_snapshot
 * @property int|null $seller_id
 * @property string|null $seller_name
 * @property string|null $user_name
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $expected_date
 * @property DeliveryMethod $delivery_method
 * @property array<string, string|null>|null $delivery_address
 * @property string $items_total
 * @property string $discount
 * @property string $freight
 * @property string $other_costs
 * @property string $total
 * @property PaymentCondition $payment_condition
 * @property PaymentMethod|null $payment_method
 * @property string|null $payment_notes
 * @property string|null $commercial_terms
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property bool $is_quick
 * @property bool $reserves_stock
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $balance_closed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Company $company
 * @property-read Party|null $customer
 * @property-read SaleQuote|null $quote
 */
class SaleOrder extends Model
{
    protected $fillable = ['company_id', 'number', 'status', 'origin', 'sale_quote_id', 'external_reference', 'customer_party_id', 'customer_snapshot', 'seller_id', 'seller_name', 'user_id', 'user_name', 'order_date', 'expected_date', 'delivery_method', 'delivery_address', 'items_total', 'discount', 'freight', 'other_costs', 'total', 'payment_condition', 'payment_method', 'payment_notes', 'commercial_terms', 'notes', 'internal_notes', 'is_quick', 'reserves_stock', 'idempotency_key', 'request_hash', 'confirmed_at', 'completed_at', 'balance_closed_at'];

    protected $attributes = ['items_total' => '0', 'discount' => '0', 'freight' => '0', 'other_costs' => '0', 'total' => '0', 'payment_condition' => 'undefined', 'origin' => 'direct', 'delivery_method' => 'pickup', 'is_quick' => false, 'reserves_stock' => false];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SaleOrderStatus::class,
            'origin' => SaleOrigin::class,
            'customer_snapshot' => 'array',
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'delivery_method' => DeliveryMethod::class,
            'delivery_address' => 'array',
            'items_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'freight' => 'decimal:2',
            'other_costs' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_condition' => PaymentCondition::class,
            'payment_method' => PaymentMethod::class,
            'is_quick' => 'boolean',
            'reserves_stock' => 'boolean',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'balance_closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_party_id');
    }

    /** @return BelongsTo<SaleQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(SaleQuote::class, 'sale_quote_id');
    }

    /** @return HasMany<SaleOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleOrderItem::class)->orderBy('position');
    }

    /** @return HasMany<SalePaymentSchedule, $this> */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(SalePaymentSchedule::class)->orderBy('sequence');
    }

    /** @return HasMany<SaleStatusChange, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SaleStatusChange::class)->orderBy('id');
    }

    /** @return HasMany<SaleFulfillment, $this> */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(SaleFulfillment::class)->orderBy('id');
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }

    /** @param Builder<SaleOrder> $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereIn('status', [SaleOrderStatus::Confirmed, SaleOrderStatus::PartiallyFulfilled]);
    }

    /** @param Builder<SaleOrder> $query */
    #[Scope]
    protected function effective(Builder $query): void
    {
        $query->whereIn('status', SaleOrderStatus::effective());
    }
}

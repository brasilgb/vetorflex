<?php

namespace App\Modules\Sales\Models;

use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Orçamento comercial. Não movimenta estoque nem gera obrigação financeira.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property SaleQuoteStatus $status
 * @property int|null $customer_party_id
 * @property array<string, mixed>|null $customer_snapshot
 * @property int|null $seller_id
 * @property string|null $seller_name
 * @property string|null $user_name
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $valid_until
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
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property-read Company $company
 * @property-read Party|null $customer
 * @property-read SaleOrder|null $order
 */
class SaleQuote extends Model
{
    protected $fillable = ['company_id', 'number', 'status', 'customer_party_id', 'customer_snapshot', 'seller_id', 'seller_name', 'user_id', 'user_name', 'issue_date', 'valid_until', 'items_total', 'discount', 'freight', 'other_costs', 'total', 'payment_condition', 'payment_method', 'payment_notes', 'commercial_terms', 'notes', 'idempotency_key', 'request_hash', 'issued_at', 'decided_at'];

    protected $attributes = ['items_total' => '0', 'discount' => '0', 'freight' => '0', 'other_costs' => '0', 'total' => '0', 'payment_condition' => 'undefined'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SaleQuoteStatus::class,
            'customer_snapshot' => 'array',
            'issue_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'items_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'freight' => 'decimal:2',
            'other_costs' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_condition' => PaymentCondition::class,
            'payment_method' => PaymentMethod::class,
            'issued_at' => 'datetime',
            'decided_at' => 'datetime',
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

    /** @return HasMany<SaleQuoteItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleQuoteItem::class)->orderBy('position');
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

    /** @return HasOne<SaleOrder, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(SaleOrder::class);
    }

    public function isExpiredOn(string $today): bool
    {
        return $this->status->canExpire() && $this->valid_until->format('Y-m-d') < $today;
    }
}

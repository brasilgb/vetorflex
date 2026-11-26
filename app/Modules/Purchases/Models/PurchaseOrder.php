<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Purchases\Enums\PaymentCondition;
use App\Modules\Purchases\Enums\PaymentMethod;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $supplier_profile_id
 * @property array<string, string|null> $supplier_snapshot
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $expected_date
 * @property string|null $user_name
 * @property string $items_total
 * @property string $discount
 * @property string $freight
 * @property string $insurance
 * @property string $other_costs
 * @property string $total
 * @property PaymentCondition $payment_condition
 * @property PaymentMethod|null $payment_method
 * @property string|null $payment_notes
 * @property string|null $external_reference
 * @property string|null $notes
 * @property bool $is_quick
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $balance_closed_at
 * @property-read Company $company
 * @property-read SupplierProfile $supplier
 */
class PurchaseOrder extends Model
{
    protected $fillable = ['company_id', 'number', 'supplier_profile_id', 'supplier_snapshot', 'status', 'order_date', 'expected_date', 'user_id', 'user_name', 'items_total', 'discount', 'freight', 'insurance', 'other_costs', 'total', 'payment_condition', 'payment_method', 'payment_notes', 'external_reference', 'notes', 'is_quick', 'idempotency_key', 'request_hash', 'confirmed_at', 'balance_closed_at'];

    protected $attributes = ['items_total' => '0', 'discount' => '0', 'freight' => '0', 'insurance' => '0', 'other_costs' => '0', 'total' => '0', 'payment_condition' => 'undefined', 'is_quick' => false];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'supplier_snapshot' => 'array',
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'items_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'freight' => 'decimal:2',
            'insurance' => 'decimal:2',
            'other_costs' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_condition' => PaymentCondition::class,
            'payment_method' => PaymentMethod::class,
            'is_quick' => 'boolean',
            'confirmed_at' => 'datetime',
            'balance_closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<SupplierProfile, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierProfile::class, 'supplier_profile_id');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('position');
    }

    /** @return HasMany<PurchasePaymentSchedule, $this> */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PurchasePaymentSchedule::class)->orderBy('sequence');
    }

    /** @return HasMany<PurchaseReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderBy('id');
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class)->orderBy('id');
    }

    /** @return HasMany<PurchaseStatusChange, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(PurchaseStatusChange::class)->orderBy('id');
    }

    /** @param Builder<PurchaseOrder> $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereIn('status', [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::PartiallyReceived]);
    }

    /** @param Builder<PurchaseOrder> $query */
    #[Scope]
    protected function late(Builder $query, string $today): void
    {
        $query->whereIn('status', [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::PartiallyReceived])->where('expected_date', '<', $today);
    }
}

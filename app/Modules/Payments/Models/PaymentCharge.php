<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\ChargeOrigin;
use App\Modules\Payments\Enums\ChargeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cobrança. Valores em `decimal(15,2)`; a situação é interna (`ChargeStatus`) e o estado do provedor fica à parte
 * (`provider_status`). `active_origin_key` (único por empresa) impede duas cobranças abertas para a mesma venda,
 * título ou atendimento de PDV.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int|null $payment_provider_account_id
 * @property string|null $provider
 * @property string|null $environment
 * @property string $method
 * @property ChargeStatus $status
 * @property string $amount
 * @property string $paid_amount
 * @property string $refunded_amount
 * @property string|null $fee_amount
 * @property string|null $net_amount
 * @property string $description
 * @property string|null $reference
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $expires_at
 * @property int|null $payer_party_id
 * @property string|null $payer_name
 * @property string|null $payer_document
 * @property ChargeOrigin $origin
 * @property string|null $origin_type
 * @property int|null $origin_id
 * @property string|null $origin_label
 * @property string|null $active_origin_key
 * @property string|null $provider_charge_id
 * @property string|null $provider_status
 * @property int|null $provider_location_id
 * @property string|null $pix_copy_paste
 * @property string|null $qr_code_image
 * @property string|null $payment_url
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_name
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable|null $created_at
 * @property-read PaymentProviderAccount|null $account
 * @property-read Collection<int, PaymentTransaction> $transactions
 * @property-read Collection<int, PaymentRefund> $refunds
 * @property-read Collection<int, PaymentChargeEvent> $events
 */
class PaymentCharge extends Model
{
    protected $fillable = [
        'company_id', 'number', 'payment_provider_account_id', 'provider', 'environment', 'method', 'status', 'amount', 'paid_amount', 'refunded_amount',
        'fee_amount', 'net_amount', 'description', 'reference', 'due_date', 'expires_at', 'payer_party_id', 'payer_name', 'payer_document', 'origin',
        'origin_type', 'origin_id', 'origin_label', 'active_origin_key', 'provider_charge_id', 'provider_status', 'provider_location_id', 'pix_copy_paste',
        'qr_code_image', 'payment_url', 'paid_at', 'cancelled_at', 'cancelled_by_name', 'cancellation_reason', 'last_synced_at', 'failure_reason', 'metadata',
        'idempotency_key', 'request_hash', 'created_by', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChargeStatus::class,
            'origin' => ChargeOrigin::class,
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<PaymentProviderAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccount::class, 'payment_provider_account_id');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @return HasMany<PaymentChargeEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentChargeEvent::class);
    }
}

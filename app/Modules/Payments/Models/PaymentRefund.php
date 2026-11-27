<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\RefundStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Devolução (estorno) de um pagamento recebido, solicitada ao provedor e confirmada por ele.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payment_charge_id
 * @property int $payment_transaction_id
 * @property string $provider_refund_id
 * @property string|null $rtr_id
 * @property string $amount
 * @property RefundStatus $status
 * @property string $reason
 * @property string|null $failure_reason
 * @property int|null $requested_by
 * @property string|null $requested_by_name
 * @property CarbonImmutable|null $completed_at
 * @property string $idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property-read PaymentTransaction $transaction
 */
class PaymentRefund extends Model
{
    protected $fillable = ['company_id', 'payment_charge_id', 'payment_transaction_id', 'provider_refund_id', 'rtr_id', 'amount', 'status', 'reason', 'failure_reason', 'requested_by', 'requested_by_name', 'completed_at', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'status' => RefundStatus::class, 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<PaymentTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}

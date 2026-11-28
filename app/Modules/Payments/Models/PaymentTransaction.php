<?php

namespace App\Modules\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento confirmado pelo provedor (no Pix, um `endToEndId`), único por empresa: o mesmo pagamento nunca é
 * registrado duas vezes, por mais que o webhook se repita.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payment_charge_id
 * @property string $provider_transaction_id
 * @property string $amount
 * @property string|null $fee_amount
 * @property string|null $net_amount
 * @property string $refunded_amount
 * @property CarbonImmutable $paid_at
 * @property string|null $payer_name
 * @property string|null $payer_document
 * @property-read PaymentCharge $charge
 */
class PaymentTransaction extends Model
{
    protected $fillable = ['company_id', 'payment_charge_id', 'provider_transaction_id', 'amount', 'fee_amount', 'net_amount', 'refunded_amount', 'paid_at', 'payer_name', 'payer_document'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fee_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'refunded_amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<PaymentCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(PaymentCharge::class, 'payment_charge_id');
    }
}

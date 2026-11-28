<?php

namespace App\Modules\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Histórico imutável da cobrança: criação, emissão, sincronizações, pagamentos, cancelamento e devoluções.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payment_charge_id
 * @property string $event
 * @property string $description
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $user_id
 * @property string|null $user_name
 * @property CarbonImmutable|null $created_at
 */
class PaymentChargeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'payment_charge_id', 'event', 'description', 'from_status', 'to_status', 'user_id', 'user_name'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico da cobrança é imutável.'));
        static::deleting(fn () => throw new LogicException('O histórico da cobrança não pode ser excluído.'));
    }
}

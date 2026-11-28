<?php

namespace App\Modules\Pos\Models;

use App\Modules\Pos\Enums\CashMovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Sangria, suprimento, ajuste ou restituição em dinheiro. Imutável: correções são novos lançamentos autorizados.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_session_id
 * @property CashMovementType $type
 * @property string $amount
 * @property string $reason
 * @property string $user_name
 * @property string|null $authorized_by_name
 * @property bool $is_post_close
 * @property int|null $pos_sale_id
 * @property CarbonImmutable $occurred_at
 * @property string $idempotency_key
 */
class PosCashMovement extends Model
{
    protected $fillable = ['company_id', 'pos_session_id', 'type', 'amount', 'reason', 'user_id', 'user_name', 'authorized_by', 'authorized_by_name', 'is_post_close', 'pos_sale_id', 'occurred_at', 'idempotency_key'];

    protected function casts(): array
    {
        return ['type' => CashMovementType::class, 'amount' => 'decimal:2', 'is_post_close' => 'boolean', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Movimentações de caixa não podem ser alteradas.'));
        static::deleting(fn () => throw new LogicException('Movimentações de caixa não podem ser excluídas.'));
    }
}

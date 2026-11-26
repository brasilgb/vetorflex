<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\MovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Lançamento do livro financeiro. Valor com sinal: positivo entra, negativo sai. Imutável depois de criado,
 * exceto pela conciliação; correções são feitas por estorno.
 *
 * @property int $id
 * @property int $company_id
 * @property int $finance_account_id
 * @property MovementType $type
 * @property string $amount
 * @property bool $is_operational Entra no resultado (receita ou despesa). Transferências e ajustes não entram.
 * @property CarbonImmutable $occurred_at
 * @property string $description
 * @property int|null $finance_category_id
 * @property int|null $finance_settlement_id
 * @property int|null $finance_transfer_id
 * @property int|null $reversal_of_id
 * @property string|null $method
 * @property string|null $user_name
 * @property string $idempotency_key
 * @property CarbonImmutable|null $reconciled_at
 * @property string|null $reconciled_by_name
 * @property-read FinanceAccount $account
 * @property-read FinanceCategory|null $category
 */
class FinanceMovement extends Model
{
    private const RECONCILIATION_FIELDS = ['reconciled_at', 'reconciled_by', 'reconciled_by_name', 'updated_at'];

    protected $fillable = ['company_id', 'finance_account_id', 'type', 'amount', 'is_operational', 'occurred_at', 'description', 'finance_category_id', 'finance_settlement_id', 'finance_transfer_id', 'reversal_of_id', 'method', 'user_id', 'user_name', 'idempotency_key', 'external_reference'];

    protected function casts(): array
    {
        return ['type' => MovementType::class, 'amount' => 'decimal:2', 'is_operational' => 'boolean', 'occurred_at' => 'date:Y-m-d', 'reconciled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            if (array_diff(array_keys($movement->getDirty()), self::RECONCILIATION_FIELDS) !== []) {
                throw new LogicException('Movimentações financeiras não podem ser alteradas; registre um estorno.');
            }
        });
        static::deleting(fn () => throw new LogicException('Movimentações financeiras não podem ser excluídas.'));
    }

    /** @return BelongsTo<FinanceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    /** @return BelongsTo<FinanceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /** @return BelongsTo<FinanceSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinanceSettlement::class, 'finance_settlement_id');
    }
}

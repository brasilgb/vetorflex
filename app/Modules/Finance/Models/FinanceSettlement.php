<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Finance\Enums\SettlementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baixa de um título. Valor movimentado = principal + juros + multa − desconto.
 * Baixa externa (liquidada fora do sistema, na importação) não tem conta nem movimentação.
 *
 * @property int $id
 * @property int $company_id
 * @property int $finance_title_id
 * @property int|null $finance_account_id
 * @property SettlementStatus $status
 * @property bool $is_external
 * @property CarbonImmutable $settled_at
 * @property PaymentMethod|null $method
 * @property string $principal
 * @property string $interest
 * @property string $fine
 * @property string $discount
 * @property string $amount
 * @property string|null $notes
 * @property string|null $user_name
 * @property string $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_name
 * @property string|null $reversal_reason
 * @property-read FinanceTitle $title
 * @property-read FinanceAccount|null $account
 */
class FinanceSettlement extends Model
{
    protected $fillable = ['company_id', 'finance_title_id', 'finance_account_id', 'status', 'is_external', 'settled_at', 'method', 'principal', 'interest', 'fine', 'discount', 'amount', 'notes', 'user_id', 'user_name', 'idempotency_key', 'request_hash', 'reversed_at', 'reversed_by_name', 'reversal_reason'];

    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'is_external' => 'boolean',
            'settled_at' => 'date:Y-m-d',
            'method' => PaymentMethod::class,
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'fine' => 'decimal:2',
            'discount' => 'decimal:2',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FinanceTitle, $this> */
    public function title(): BelongsTo
    {
        return $this->belongsTo(FinanceTitle::class, 'finance_title_id');
    }

    /** @return BelongsTo<FinanceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }
}

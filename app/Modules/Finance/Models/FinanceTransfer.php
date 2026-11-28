<?php

namespace App\Modules\Finance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transferência entre contas da empresa: uma saída e uma entrada com a mesma referência, fora do resultado.
 *
 * @property int $id
 * @property int $company_id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property string $amount
 * @property CarbonImmutable $transferred_at
 * @property string|null $description
 * @property string $status
 * @property string|null $user_name
 * @property string $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_name
 * @property string|null $reversal_reason
 * @property-read FinanceAccount $from
 * @property-read FinanceAccount $to
 */
class FinanceTransfer extends Model
{
    protected $fillable = ['company_id', 'from_account_id', 'to_account_id', 'amount', 'transferred_at', 'description', 'status', 'user_id', 'user_name', 'idempotency_key', 'request_hash', 'reversed_at', 'reversed_by_name', 'reversal_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transferred_at' => 'date:Y-m-d', 'reversed_at' => 'datetime'];
    }

    /** @return BelongsTo<FinanceAccount, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'from_account_id');
    }

    /** @return BelongsTo<FinanceAccount, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'to_account_id');
    }
}

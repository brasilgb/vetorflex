<?php

namespace App\Modules\Finance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Redução (positivo) ou restabelecimento (negativo) do valor devido, originado de cancelamentos e devoluções comerciais.
 *
 * @property int $id
 * @property int $finance_title_id
 * @property string $amount
 * @property string $reason
 * @property string|null $source_key
 * @property CarbonImmutable $occurred_at
 */
class FinanceTitleAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = ['company_id', 'finance_title_id', 'amount', 'reason', 'source_key', 'occurred_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}

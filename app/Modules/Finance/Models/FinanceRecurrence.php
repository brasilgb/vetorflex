<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\Frequency;
use App\Modules\Finance\Enums\TitleKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receita ou despesa recorrente. Gera títulos pendentes por comando agendado; nunca dá baixa automática.
 *
 * @property int $id
 * @property int $company_id
 * @property TitleKind $kind
 * @property string $description
 * @property int|null $party_id
 * @property int|null $finance_category_id
 * @property string $amount
 * @property Frequency $frequency
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $generated_until
 * @property CarbonImmutable|null $ended_at
 * @property string|null $user_name
 * @property-read FinanceCategory|null $category
 */
class FinanceRecurrence extends Model
{
    protected $fillable = ['company_id', 'kind', 'description', 'party_id', 'finance_category_id', 'amount', 'frequency', 'start_date', 'end_date', 'generated_until', 'ended_at', 'user_id', 'user_name'];

    protected function casts(): array
    {
        return [
            'kind' => TitleKind::class,
            'amount' => 'decimal:2',
            'frequency' => Frequency::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'generated_until' => 'date:Y-m-d',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FinanceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /** @return HasMany<FinanceTitle, $this> */
    public function titles(): HasMany
    {
        return $this->hasMany(FinanceTitle::class)->orderBy('due_date');
    }
}

<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Título a receber ou a pagar. Saldo em aberto = valor − ajustes − principal liquidado − valor cancelado.
 * "Vencido" não é gravado: é derivado do vencimento e do saldo em aberto.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property TitleKind $kind
 * @property TitleStatus $status
 * @property TitleOrigin $origin
 * @property int|null $party_id
 * @property string|null $party_name
 * @property string $description
 * @property int|null $finance_category_id
 * @property string $amount
 * @property string $adjusted_amount
 * @property string $settled_principal
 * @property string $cancelled_amount
 * @property string $interest_total
 * @property string $fine_total
 * @property string $discount_total
 * @property CarbonImmutable $competence_date
 * @property CarbonImmutable $due_date
 * @property int $installment_number
 * @property int $installment_count
 * @property string|null $group_key
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $source_key
 * @property string|null $source_label
 * @property int|null $finance_recurrence_id
 * @property int|null $renegotiation_id
 * @property int|null $renegotiated_in_id
 * @property string|null $notes
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $user_name
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property CarbonImmutable|null $created_at
 * @property-read Company $company
 * @property-read FinanceCategory|null $category
 * @property-read Party|null $party
 */
class FinanceTitle extends Model
{
    protected $fillable = ['company_id', 'number', 'kind', 'status', 'origin', 'party_id', 'party_name', 'description', 'finance_category_id', 'amount', 'adjusted_amount', 'settled_principal', 'cancelled_amount', 'interest_total', 'fine_total', 'discount_total', 'competence_date', 'due_date', 'installment_number', 'installment_count', 'group_key', 'source_type', 'source_id', 'source_key', 'source_label', 'finance_recurrence_id', 'renegotiation_id', 'renegotiated_in_id', 'notes', 'cancelled_at', 'cancellation_reason', 'user_id', 'user_name', 'idempotency_key', 'request_hash'];

    protected $attributes = ['adjusted_amount' => '0', 'settled_principal' => '0', 'cancelled_amount' => '0', 'interest_total' => '0', 'fine_total' => '0', 'discount_total' => '0', 'installment_number' => 1, 'installment_count' => 1];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'kind' => TitleKind::class,
            'status' => TitleStatus::class,
            'origin' => TitleOrigin::class,
            'amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'settled_principal' => 'decimal:2',
            'cancelled_amount' => 'decimal:2',
            'interest_total' => 'decimal:2',
            'fine_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'competence_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<FinanceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<FinanceSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(FinanceSettlement::class)->orderBy('id');
    }

    /** @return HasMany<FinanceTitleEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FinanceTitleEvent::class)->orderBy('id');
    }

    /** @return HasMany<FinanceTitleAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(FinanceTitleAdjustment::class)->orderBy('id');
    }

    /** @return HasMany<FinanceAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(FinanceAttachment::class)->orderBy('id');
    }

    public function openBalance(): BigDecimal
    {
        return BigDecimal::of($this->amount)->minus($this->adjusted_amount)->minus($this->settled_principal)->minus($this->cancelled_amount);
    }

    /** Valor devido depois dos ajustes de integração (devoluções, cancelamentos comerciais). */
    public function effectiveAmount(): BigDecimal
    {
        return BigDecimal::of($this->amount)->minus($this->adjusted_amount);
    }

    public function isOverdueOn(string $today): bool
    {
        return $this->status->isOpen() && $this->due_date->format('Y-m-d') < $today && $this->openBalance()->isPositive();
    }

    /** Situação calculada pelos valores, sem estados inconsistentes. */
    public function computedStatus(): TitleStatus
    {
        if ($this->renegotiated_in_id !== null) {
            return TitleStatus::Renegotiated;
        }

        if ($this->openBalance()->isPositive()) {
            return BigDecimal::of($this->settled_principal)->isPositive() ? TitleStatus::Partial : TitleStatus::Pending;
        }

        return BigDecimal::of($this->settled_principal)->isPositive() ? TitleStatus::Settled : TitleStatus::Cancelled;
    }

    /** @param Builder<FinanceTitle> $query */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial]);
    }

    /** @param Builder<FinanceTitle> $query */
    #[Scope]
    protected function overdue(Builder $query, string $today): void
    {
        $query->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])->where('due_date', '<', $today);
    }
}

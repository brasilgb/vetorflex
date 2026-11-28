<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\FinanceRenegotiation;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Parties\Models\Party;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Títulos a receber e a pagar: lançamento (com parcelas), edição antes de qualquer baixa, cancelamento do saldo,
 * ajustes de integração e renegociação. A situação é sempre recalculada pelos valores.
 */
class FinanceTitleService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private SequenceService $sequences,
        private AuditService $audit,
    ) {}

    /**
     * Dados: kind, description, amount, competence_date, due_date, party_id, finance_category_id, notes,
     * installments (quantidade, mensais) ou schedule (lista de {due_date, amount}), idempotency_key.
     * Campos de integração: origin, source_type, source_id, source_key, source_label.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, FinanceTitle>
     */
    public function create(Company $company, ?User $user, array $data): Collection
    {
        $key = $data['idempotency_key'] ?? null;
        $hash = hash('sha256', (string) json_encode(Arr::sortRecursive(Arr::except($data, ['idempotency_key']))));

        try {
            return DB::transaction(function () use ($company, $user, $data, $key, $hash): Collection {
                $existing = $this->existing($company, $data, $key, $hash);

                if ($existing !== null) {
                    return $existing;
                }

                $kind = TitleKind::from($data['kind']);
                $party = $this->party($company, $data['party_id'] ?? null);
                $category = $this->category($company, $kind, $data['finance_category_id'] ?? null);
                $schedule = $this->schedule($data);
                $groupKey = count($schedule) > 1 ? (string) Str::uuid() : null;
                $titles = new Collection;

                foreach ($schedule as $index => $row) {
                    $title = FinanceTitle::create([
                        'company_id' => $company->id,
                        'number' => $this->sequences->next($company, 'finance_title'),
                        'kind' => $kind,
                        'status' => TitleStatus::Pending,
                        'origin' => $data['origin'] ?? TitleOrigin::Manual->value,
                        'party_id' => $party?->id,
                        'party_name' => $party->name ?? ($data['party_name'] ?? null),
                        'description' => count($schedule) > 1 ? "{$data['description']} (".($index + 1).'/'.count($schedule).')' : $data['description'],
                        'finance_category_id' => $category?->id,
                        'amount' => $row['amount'],
                        'competence_date' => $data['competence_date'] ?? $row['due_date'],
                        'due_date' => $row['due_date'],
                        'installment_number' => $index + 1,
                        'installment_count' => count($schedule),
                        'group_key' => $groupKey,
                        'source_type' => $data['source_type'] ?? null,
                        'source_id' => $data['source_id'] ?? null,
                        'source_key' => isset($data['source_key']) ? $data['source_key'].(count($schedule) > 1 ? ':'.($index + 1) : '') : null,
                        'source_label' => $data['source_label'] ?? null,
                        'finance_recurrence_id' => $data['finance_recurrence_id'] ?? null,
                        'renegotiation_id' => $data['renegotiation_id'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'user_id' => $user?->id,
                        'user_name' => $user->name ?? 'Sistema',
                        'idempotency_key' => $key,
                        'request_hash' => $key === null ? null : $hash,
                    ]);
                    $this->event($title, 'created', ($title->origin === TitleOrigin::Manual ? 'Lançamento manual' : $title->origin->label()).($title->source_label ? ": {$title->source_label}" : '').'.', $title->amount, $user);
                    $titles->push($title);
                }

                $this->audit->record('finance.title_created', $company, $user, $titles->first(), ['kind' => $kind->value, 'count' => $titles->count()]);

                return $titles;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($company, $data, $key, $hash) ?? throw $exception;
        }
    }

    /**
     * Edição de dados descritivos. Valor e vencimento só mudam antes da primeira baixa.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FinanceTitle $title, User $user, array $data): FinanceTitle
    {
        return DB::transaction(function () use ($title, $user, $data): FinanceTitle {
            $title = $this->lock($title);

            if (! $title->status->isOpen()) {
                throw ValidationException::withMessages(['status' => 'Somente títulos em aberto podem ser editados.']);
            }

            $hasSettlements = BigDecimal::of($title->settled_principal)->isPositive();
            $party = array_key_exists('party_id', $data) ? $this->party($title->company, $data['party_id']) : $title->party;
            $category = array_key_exists('finance_category_id', $data) ? $this->category($title->company, $title->kind, $data['finance_category_id']) : $title->category;
            $title->fill([
                ...Arr::only($data, ['description', 'notes', 'competence_date']),
                'party_id' => $party?->id,
                'party_name' => $party->name ?? $title->party_name,
                'finance_category_id' => $category?->id,
            ]);

            foreach (['amount', 'due_date'] as $field) {
                $value = $field === 'amount' && isset($data['amount']) ? CommercialMath::money($data['amount']) : ($data[$field] ?? null);
                $current = $field === 'amount' ? $title->amount : $title->due_date->format('Y-m-d');

                if ($value !== null && $value !== $current) {
                    if ($hasSettlements || $title->origin !== TitleOrigin::Manual && $title->origin !== TitleOrigin::Recurrence && $field === 'amount') {
                        throw ValidationException::withMessages([$field => $hasSettlements ? 'O título já tem baixas. Use renegociação.' : 'O valor de títulos de integração é ajustado pelo documento de origem.']);
                    }

                    $title->{$field} = $value;
                }
            }

            if (BigDecimal::of($title->amount)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
            }

            $changes = array_keys($title->getDirty());
            $title->save();
            $this->refreshStatus($title);
            $this->event($title, 'updated', 'Dados alterados: '.implode(', ', $changes).'.', null, $user);

            return $title;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** Cancela o saldo em aberto. Valores já liquidados permanecem; o título liquidado em parte fica "Liquidado". */
    public function cancel(FinanceTitle $title, ?User $user, string $reason): FinanceTitle
    {
        return DB::transaction(function () use ($title, $user, $reason): FinanceTitle {
            $title = $this->lock($title);
            $open = $title->openBalance();

            if (! $title->status->isOpen() || ! $open->isPositive()) {
                throw ValidationException::withMessages(['status' => 'Este título não tem saldo em aberto para cancelar.']);
            }

            $title->forceFill([
                'cancelled_amount' => CommercialMath::money(BigDecimal::of($title->cancelled_amount)->plus($open)),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $this->refreshStatus($title);
            $this->event($title, 'cancelled', "Saldo cancelado: {$reason}", (string) $open, $user);
            $this->audit->record('finance.title_cancelled', $title->company, $user, $title, ['number' => $title->number, 'reason' => $reason]);

            return $title;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Ajuste vindo de integração: positivo reduz o valor devido, negativo o restabelece. Só alcança o saldo em aberto
     * (ou, no restabelecimento, o que foi reduzido antes). Devolve o valor efetivamente aplicado.
     */
    public function adjust(FinanceTitle $title, string $amount, string $reason, ?string $sourceKey): string
    {
        $title = $this->lock($title);
        $requested = BigDecimal::of($amount);
        $applied = $requested->isPositive()
            ? BigDecimal::min($requested, BigDecimal::max(BigDecimal::zero(), $title->openBalance()))
            : BigDecimal::max($requested, BigDecimal::of($title->adjusted_amount)->negated());

        if ($applied->isZero() || $title->status === TitleStatus::Renegotiated) {
            return '0.00';
        }

        $title->forceFill(['adjusted_amount' => CommercialMath::money(BigDecimal::of($title->adjusted_amount)->plus($applied))])->save();
        $title->adjustments()->create(['company_id' => $title->company_id, 'amount' => CommercialMath::money($applied), 'reason' => $reason, 'source_key' => $sourceKey, 'occurred_at' => now()]);
        $this->refreshStatus($title);
        $this->event($title, 'adjusted', $reason, CommercialMath::money($applied), null);

        return CommercialMath::money($applied);
    }

    /**
     * Renegociação: o saldo em aberto dos títulos selecionados (mesmo tipo e pessoa) é substituído por novos títulos.
     * Os originais ficam "Renegociados" e apontam para a renegociação; nada é apagado.
     *
     * @param  list<int>  $titleIds
     * @param  list<array{due_date: string, amount: string}>  $schedule
     * @return Collection<int, FinanceTitle>
     */
    public function renegotiate(Company $company, User $user, array $titleIds, array $schedule, string $reason): Collection
    {
        return DB::transaction(function () use ($company, $user, $titleIds, $schedule, $reason): Collection {
            $titles = FinanceTitle::query()->where('company_id', $company->id)->whereKey($titleIds)->orderBy('id')->lockForUpdate()->get();

            if ($titles->count() !== count(array_unique($titleIds)) || $titles->isEmpty()) {
                throw ValidationException::withMessages(['titles' => 'Selecione títulos desta empresa.']);
            }

            if ($titles->pluck('kind')->unique()->count() > 1 || $titles->pluck('party_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['titles' => 'Renegocie títulos do mesmo tipo e da mesma pessoa.']);
            }

            $open = $titles->reduce(fn (BigDecimal $carry, FinanceTitle $title): BigDecimal => $carry->plus($title->status->isOpen() ? $title->openBalance() : BigDecimal::zero()), BigDecimal::zero());

            if ($titles->contains(fn (FinanceTitle $title): bool => ! $title->status->isOpen())) {
                throw ValidationException::withMessages(['titles' => 'Somente títulos em aberto podem ser renegociados.']);
            }

            $total = array_reduce($schedule, fn (BigDecimal $carry, array $row): BigDecimal => $carry->plus($row['amount']), BigDecimal::zero());

            if ($total->isLessThan($open)) {
                throw ValidationException::withMessages(['schedule' => 'O novo parcelamento não pode ser menor que o saldo renegociado ('.CommercialMath::money($open).'). Para conceder desconto, cancele parte do saldo antes.']);
            }

            /** @var FinanceTitle $first */
            $first = $titles->first();
            $renegotiation = FinanceRenegotiation::create(['company_id' => $company->id, 'kind' => $first->kind->value, 'total' => CommercialMath::money($total), 'reason' => $reason, 'user_id' => $user->id, 'user_name' => $user->name]);

            foreach ($titles as $title) {
                $balance = $title->openBalance();
                $title->forceFill(['cancelled_amount' => CommercialMath::money(BigDecimal::of($title->cancelled_amount)->plus($balance)), 'renegotiated_in_id' => $renegotiation->id])->save();
                $this->refreshStatus($title);
                $this->event($title, 'renegotiated', "Saldo renegociado (renegociação #{$renegotiation->id}): {$reason}", (string) $balance, $user);
            }

            $created = $this->create($company, $user, [
                'kind' => $first->kind->value,
                'origin' => TitleOrigin::Renegotiation->value,
                'description' => 'Renegociação de '.$titles->map(fn (FinanceTitle $title): string => "#{$title->number}")->implode(', '),
                'party_id' => $first->party_id,
                'party_name' => $first->party_name,
                'finance_category_id' => $first->finance_category_id,
                'competence_date' => now($company->timezone)->format('Y-m-d'),
                'schedule' => $schedule,
                'renegotiation_id' => $renegotiation->id,
                'source_type' => 'renegotiation',
                'source_id' => $renegotiation->id,
                'notes' => ! $total->isEqualTo($open) ? 'Inclui acréscimo de '.CommercialMath::money($total->minus($open)).' sobre o saldo renegociado.' : null,
            ]);
            $this->audit->record('finance.titles_renegotiated', $company, $user, $renegotiation, ['titles' => $titleIds]);

            return $created;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function refreshStatus(FinanceTitle $title): void
    {
        $status = $title->computedStatus();

        if ($status !== $title->status) {
            $title->forceFill(['status' => $status])->save();
        }
    }

    public function event(FinanceTitle $title, string $event, string $description, ?string $amount, ?User $user): void
    {
        $title->events()->create([
            'company_id' => $title->company_id,
            'event' => $event,
            'description' => mb_substr($description, 0, 500),
            'amount' => $amount,
            'user_id' => $user?->id,
            'user_name' => $user->name ?? 'Sistema',
            'occurred_at' => now(),
        ]);
    }

    public function lock(FinanceTitle $title): FinanceTitle
    {
        return FinanceTitle::query()->where('company_id', $title->company_id)->lockForUpdate()->findOrFail($title->id);
    }

    /**
     * Parcelas: lista explícita, ou quantidade de parcelas mensais a partir do vencimento (centavos na última).
     *
     * @param  array<string, mixed>  $data
     * @return list<array{due_date: string, amount: string}>
     */
    private function schedule(array $data): array
    {
        if (! empty($data['schedule'])) {
            $rows = array_values(array_map(fn (array $row): array => ['due_date' => (string) $row['due_date'], 'amount' => CommercialMath::money((string) $row['amount'])], $data['schedule']));
        } else {
            $count = max(1, (int) ($data['installments'] ?? 1));
            $cents = BigDecimal::of($data['amount'])->toScale(2)->getUnscaledValue();
            $share = $cents->quotient($count);
            $due = CarbonImmutable::parse($data['due_date']);
            $rows = [];

            for ($index = 0; $index < $count; $index++) {
                $value = $index === $count - 1 ? $cents->minus($share->multipliedBy($count - 1)) : $share;
                $rows[] = ['due_date' => $due->addMonthsNoOverflow($index)->format('Y-m-d'), 'amount' => (string) BigDecimal::ofUnscaledValue(BigInteger::of($value), 2)];
            }
        }

        foreach ($rows as $index => $row) {
            if (! BigDecimal::of($row['amount'])->isPositive()) {
                throw ValidationException::withMessages(["schedule.{$index}.amount" => 'Cada parcela precisa ter valor maior que zero.']);
            }
        }

        return $rows;
    }

    private function party(Company $company, mixed $partyId): ?Party
    {
        if ($partyId === null || $partyId === '') {
            return null;
        }

        return Party::query()->where('company_id', $company->id)->find((int) $partyId)
            ?? throw ValidationException::withMessages(['party_id' => 'Selecione uma pessoa desta empresa.']);
    }

    private function category(Company $company, TitleKind $kind, mixed $categoryId): ?FinanceCategory
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $category = FinanceCategory::query()->where('company_id', $company->id)->find((int) $categoryId);

        if ($category === null || $category->kind !== $kind->categoryKind()) {
            throw ValidationException::withMessages(['finance_category_id' => 'Selecione uma categoria de '.mb_strtolower($kind->categoryKind()->label()).' desta empresa.']);
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, FinanceTitle>|null
     */
    private function existing(Company $company, array $data, ?string $key, string $hash): ?Collection
    {
        $query = FinanceTitle::query()->where('company_id', $company->id);

        if ($key !== null) {
            $titles = (clone $query)->where('idempotency_key', $key)->orderBy('installment_number')->get();

            if ($titles->isNotEmpty() && ! hash_equals((string) $titles->first()->request_hash, $hash)) {
                throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada para outro lançamento. Recarregue a página.']);
            }

            return $titles->isEmpty() ? null : $titles;
        }

        if (isset($data['source_key'])) {
            $titles = (clone $query)->where(fn ($builder) => $builder->where('source_key', $data['source_key'])->orWhere('source_key', 'like', $data['source_key'].':%'))->orderBy('installment_number')->get();

            return $titles->isEmpty() ? null : $titles;
        }

        return null;
    }
}

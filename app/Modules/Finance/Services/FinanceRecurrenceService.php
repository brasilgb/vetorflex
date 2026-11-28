<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Models\FinanceRecurrence;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Receitas e despesas recorrentes. A geração cria um título pendente por ocorrência até o horizonte configurado,
 * com chave de origem por data: repetir a geração não duplica títulos. Não há baixa automática.
 */
class FinanceRecurrenceService
{
    public function __construct(private FinanceTitleService $titles, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $user, array $data): FinanceRecurrence
    {
        $recurrence = FinanceRecurrence::create([
            ...Arr::only($data, ['kind', 'description', 'party_id', 'finance_category_id', 'frequency', 'start_date', 'end_date']),
            'company_id' => $company->id,
            'amount' => CommercialMath::money($data['amount']),
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);
        $this->audit->record('finance.recurrence_created', $company, $user, $recurrence);
        $this->generate($recurrence, $this->horizon($company));

        return $recurrence;
    }

    /** Encerra a recorrência. Títulos já gerados continuam em aberto e podem ser cancelados individualmente. */
    public function end(FinanceRecurrence $recurrence, User $user): void
    {
        $recurrence->update(['ended_at' => now()]);
        $this->audit->record('finance.recurrence_ended', null, $user, $recurrence);
    }

    /** @return int Quantidade de títulos criados. */
    public function generateAll(Company $company): int
    {
        $created = 0;
        $until = $this->horizon($company);

        foreach (FinanceRecurrence::query()->where('company_id', $company->id)->whereNull('ended_at')->orderBy('id')->get() as $recurrence) {
            $created += $this->generate($recurrence, $until);
        }

        return $created;
    }

    public function generate(FinanceRecurrence $recurrence, string $until): int
    {
        if ($recurrence->ended_at !== null) {
            return 0;
        }

        $company = Company::query()->findOrFail($recurrence->company_id);
        $start = CarbonImmutable::parse($recurrence->start_date->format('Y-m-d'));
        $last = $recurrence->end_date === null || $recurrence->end_date->format('Y-m-d') > $until ? $until : $recurrence->end_date->format('Y-m-d');
        $created = 0;

        for ($index = 0; ($date = $recurrence->frequency->occurrence($start, $index)->format('Y-m-d')) <= $last; $index++) {
            $key = "recurrence:{$recurrence->id}:{$date}";

            if (FinanceTitle::query()->where('company_id', $company->id)->where('source_key', $key)->exists()) {
                continue;
            }

            DB::transaction(fn () => $this->titles->create($company, null, [
                'kind' => $recurrence->kind->value,
                'origin' => TitleOrigin::Recurrence->value,
                'description' => $recurrence->description,
                'amount' => $recurrence->amount,
                'competence_date' => $date,
                'due_date' => $date,
                'party_id' => $recurrence->party_id,
                'finance_category_id' => $recurrence->finance_category_id,
                'finance_recurrence_id' => $recurrence->id,
                'source_type' => 'recurrence',
                'source_id' => $recurrence->id,
                'source_key' => $key,
                'source_label' => "{$recurrence->frequency->label()} — ".CarbonImmutable::parse($date)->format('d/m/Y'),
            ]));
            $created++;
        }

        $recurrence->update(['generated_until' => $last]);

        return $created;
    }

    private function horizon(Company $company): string
    {
        return now($company->timezone)->addDays((int) config('finance.recurrence_horizon_days'))->format('Y-m-d');
    }
}

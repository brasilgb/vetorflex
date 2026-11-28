<?php

namespace App\Modules\Commercial\Services;

use App\Models\User;
use App\Modules\Commercial\Enums\CommercialStatus;
use App\Modules\Core\Services\AuditService;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Período de teste controlado pelo servidor. Vencido o teste, a empresa continua acessando e consultando seus dados,
 * mas as operações de gravação ficam bloqueadas até a contratação. Não há cobrança automática: contratar é um
 * registro expresso do RootAdmin. Ativar ou desativar módulos nunca reinicia o teste.
 */
class TrialService
{
    public function __construct(private AuditService $audit) {}

    /** Situação efetiva, considerando o vencimento mesmo antes da rotina de expiração. */
    public function status(Company $company): CommercialStatus
    {
        if ($company->commercial_status === CommercialStatus::Trial && $company->trial_ends_at !== null && $company->trial_ends_at->isPast()) {
            return CommercialStatus::Expired;
        }

        return $company->commercial_status;
    }

    public function blocksWrites(Company $company): bool
    {
        return $this->status($company) === CommercialStatus::Expired;
    }

    /** @return array{status: string, label: string, trial_started_at: string|null, trial_ends_at: string|null, days_left: int|null, blocks_writes: bool} */
    public function summary(Company $company): array
    {
        $status = $this->status($company);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'trial_started_at' => $company->trial_started_at?->toIso8601String(),
            'trial_ends_at' => $company->trial_ends_at?->toIso8601String(),
            'days_left' => $status === CommercialStatus::Trial && $company->trial_ends_at !== null ? max(0, (int) ceil(now()->diffInHours($company->trial_ends_at) / 24)) : null,
            'blocks_writes' => $status === CommercialStatus::Expired,
        ];
    }

    /** Prorroga (ou reabre) o teste até a data informada, com justificativa auditada. */
    public function extend(Company $company, User $actor, CarbonImmutable $until, string $reason): Company
    {
        if ($company->commercial_status === CommercialStatus::Active) {
            throw ValidationException::withMessages(['trial_ends_at' => 'A empresa já está contratada.']);
        }

        if ($until->lte(now()) || ($company->trial_ends_at !== null && $until->lte($company->trial_ends_at))) {
            throw ValidationException::withMessages(['trial_ends_at' => 'Informe uma data posterior ao vencimento atual e a hoje.']);
        }

        $before = $company->trial_ends_at?->toIso8601String();
        $company->update(['commercial_status' => CommercialStatus::Trial, 'trial_started_at' => $company->trial_started_at ?? now(), 'trial_ends_at' => $until->endOfDay()]);
        $this->audit->record('company.trial_extended', $company, $actor, $company, ['from' => $before, 'to' => $company->trial_ends_at?->toIso8601String(), 'reason' => $reason]);

        return $company;
    }

    /** Registra a contratação expressa (sem cobrança automática). */
    public function contract(Company $company, User $actor, string $reason): Company
    {
        $company->update(['commercial_status' => CommercialStatus::Active, 'contracted_at' => $company->contracted_at ?? now()]);
        $this->audit->record('company.contracted', $company, $actor, $company, ['reason' => $reason]);

        return $company;
    }

    /** Marca como encerrados os testes vencidos (rotina diária); o bloqueio já vale pelo cálculo de `status`. */
    public function expireOverdue(): int
    {
        return Company::query()->where('commercial_status', CommercialStatus::Trial)->where('trial_ends_at', '<', now())->update(['commercial_status' => CommercialStatus::Expired]);
    }
}

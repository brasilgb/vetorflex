<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro de terminais e dos operadores autorizados (usuários com vínculo ativo na empresa).
 */
class PosTerminalService
{
    public function __construct(private AuditService $audit) {}

    /** @param array{name: string, code: string, location?: string|null, is_active?: bool, receipt_width?: int|null, operators?: list<int>} $data */
    public function save(Company $company, User $user, PosTerminal $terminal, array $data): PosTerminal
    {
        return DB::transaction(function () use ($company, $user, $terminal, $data): PosTerminal {
            $operators = array_values(array_unique(array_map('intval', $data['operators'] ?? [])));
            $members = CompanyMembership::query()->where('company_id', $company->id)->where('is_active', true)->whereIn('user_id', $operators)->pluck('user_id')->all();

            if (count($members) !== count($operators)) {
                throw ValidationException::withMessages(['operators' => 'Os operadores precisam ser usuários ativos desta empresa.']);
            }

            $isNew = ! $terminal->exists;

            if (! $isNew && $terminal->is_active && ! ($data['is_active'] ?? true) && $terminal->openSession()->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Feche o caixa aberto antes de inativar o terminal.']);
            }

            $terminal->fill([
                ...Arr::only($data, ['name', 'location']),
                'company_id' => $company->id,
                'code' => mb_strtoupper(trim($data['code'])),
                'is_active' => $data['is_active'] ?? true,
                'settings' => [...($terminal->settings ?? []), 'receipt_width' => (int) ($data['receipt_width'] ?? 80) === 58 ? 58 : 80],
            ])->save();
            $terminal->operators()->sync(array_fill_keys($members, ['company_id' => $company->id]));
            $this->audit->record($isNew ? 'pos.terminal_created' : 'pos.terminal_updated', $company, $user, $terminal, ['code' => $terminal->code, 'operators' => $members]);

            return $terminal;
        });
    }
}

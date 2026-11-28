<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conciliação manual: o usuário confere o extrato da instituição e marca as movimentações correspondentes.
 * A marcação guarda data e responsável; desfazê-la exige permissão e motivo, registrado em auditoria.
 * `external_reference` fica disponível para a futura importação de extratos.
 */
class FinanceReconciliationService
{
    public function __construct(private AuditService $audit) {}

    /**
     * @param  list<int>  $movementIds
     * @return int Quantidade de movimentações conciliadas agora.
     */
    public function reconcile(FinanceAccount $account, User $user, array $movementIds): int
    {
        return DB::transaction(function () use ($account, $user, $movementIds): int {
            $movements = FinanceMovement::query()->where('company_id', $account->company_id)->where('finance_account_id', $account->id)
                ->whereKey($movementIds)->lockForUpdate()->get();

            if ($movements->count() !== count(array_unique($movementIds))) {
                throw ValidationException::withMessages(['movements' => 'Selecione movimentações desta conta.']);
            }

            $pending = $movements->whereNull('reconciled_at');

            foreach ($pending as $movement) {
                $movement->forceFill(['reconciled_at' => now(), 'reconciled_by' => $user->id, 'reconciled_by_name' => $user->name])->save();
            }

            $this->audit->record('finance.movements_reconciled', null, $user, $account, ['movements' => $pending->modelKeys()]);

            return $pending->count();
        });
    }

    public function undo(FinanceMovement $movement, User $user, string $reason): void
    {
        DB::transaction(function () use ($movement, $user, $reason): void {
            $movement = FinanceMovement::query()->lockForUpdate()->findOrFail($movement->id);

            if ($movement->reconciled_at === null) {
                throw ValidationException::withMessages(['movement' => 'Esta movimentação não está conciliada.']);
            }

            $this->audit->record('finance.reconciliation_undone', null, $user, $movement, ['reconciled_at' => $movement->reconciled_at->toIso8601String(), 'reconciled_by' => $movement->reconciled_by_name, 'reason' => $reason]);
            $movement->forceFill(['reconciled_at' => null, 'reconciled_by' => null, 'reconciled_by_name' => null])->save();
        });
    }
}

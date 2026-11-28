<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Data\MovementEntry;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Único ponto de escrita do livro financeiro. Movimentações não são alteradas nem excluídas: correções geram
 * estornos compensatórios. A chave de idempotência é única por empresa; repetir devolve o lançamento existente.
 */
class FinanceLedger
{
    public function record(MovementEntry $entry): FinanceMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('O lançamento financeiro exige uma transação aberta.');
        }

        $existing = FinanceMovement::query()->where('company_id', $entry->companyId)->where('idempotency_key', $entry->idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (BigDecimal::of($entry->amount)->isZero()) {
            throw ValidationException::withMessages(['amount' => 'O lançamento precisa ter valor.']);
        }

        return FinanceMovement::create([
            'company_id' => $entry->companyId,
            'finance_account_id' => $entry->accountId,
            'type' => $entry->type,
            'amount' => CommercialMath::money($entry->amount),
            'is_operational' => $entry->isOperational,
            'occurred_at' => $entry->occurredAt,
            'description' => $entry->description,
            'finance_category_id' => $entry->categoryId,
            'finance_settlement_id' => $entry->settlementId,
            'finance_transfer_id' => $entry->transferId,
            'reversal_of_id' => $entry->reversalOfId,
            'method' => $entry->method,
            'user_id' => $entry->user?->id,
            'user_name' => $entry->user?->name,
            'idempotency_key' => $entry->idempotencyKey,
        ]);
    }

    /** Estorno: lançamento oposto, na mesma conta e categoria, com referência ao original. */
    public function reverse(FinanceMovement $movement, ?User $user, string $reason, string $occurredAt): FinanceMovement
    {
        if ($movement->type === MovementType::Reversal) {
            throw ValidationException::withMessages(['movement' => 'Um estorno não pode ser estornado.']);
        }

        return $this->record(new MovementEntry(
            companyId: $movement->company_id,
            accountId: $movement->finance_account_id,
            type: MovementType::Reversal,
            amount: (string) BigDecimal::of($movement->amount)->negated(),
            occurredAt: $occurredAt,
            description: "Estorno: {$movement->description} — {$reason}",
            idempotencyKey: "reversal:{$movement->id}",
            isOperational: $movement->is_operational,
            categoryId: $movement->finance_category_id,
            settlementId: $movement->finance_settlement_id,
            transferId: $movement->finance_transfer_id,
            method: $movement->method,
            user: $user,
            reversalOfId: $movement->id,
        ));
    }

    public function balanceOf(FinanceAccount $account, ?string $until = null): string
    {
        $movements = (string) ($account->movements()->when($until, fn ($query, string $date) => $query->where('occurred_at', '<=', $date))->sum('amount') ?: '0');

        return CommercialMath::money(BigDecimal::of($account->opening_balance)->plus($movements));
    }

    /**
     * Saldos de todas as contas da empresa, numa consulta.
     *
     * @return array<int, string>
     */
    public function balances(int $companyId, ?string $until = null): array
    {
        $sums = FinanceMovement::query()->where('company_id', $companyId)
            ->when($until, fn ($query, string $date) => $query->where('occurred_at', '<=', $date))
            ->groupBy('finance_account_id')->selectRaw('finance_account_id, sum(amount) as total')->pluck('total', 'finance_account_id');
        $balances = [];

        foreach (FinanceAccount::query()->where('company_id', $companyId)->get() as $account) {
            $balances[$account->id] = CommercialMath::money(BigDecimal::of($account->opening_balance)->plus((string) ($sums[$account->id] ?? '0')));
        }

        return $balances;
    }
}

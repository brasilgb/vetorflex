<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Data\MovementEntry;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contas financeiras. Saldo inicial e data só mudam enquanto a conta não tem movimentações; depois, apenas por
 * ajuste identificado, com justificativa.
 */
class FinanceAccountService
{
    private const FIELDS = ['name', 'type', 'institution', 'notes'];

    public function __construct(private FinanceLedger $ledger, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $user, array $data): FinanceAccount
    {
        $account = FinanceAccount::create([
            ...Arr::only($data, self::FIELDS),
            'company_id' => $company->id,
            'opening_balance' => CommercialMath::money($data['opening_balance'] ?? '0'),
            'opening_date' => $data['opening_date'],
        ]);
        $this->audit->record('finance.account_created', $company, $user, $account, ['name' => $account->name]);

        return $account;
    }

    /** @param array<string, mixed> $data */
    public function update(FinanceAccount $account, User $user, array $data): FinanceAccount
    {
        return DB::transaction(function () use ($account, $user, $data): FinanceAccount {
            $account = FinanceAccount::query()->lockForUpdate()->findOrFail($account->id);
            $account->fill(Arr::only($data, self::FIELDS));
            $opening = CommercialMath::money($data['opening_balance'] ?? $account->opening_balance);
            $openingChanged = $opening !== $account->opening_balance || ($data['opening_date'] ?? $account->opening_date->format('Y-m-d')) !== $account->opening_date->format('Y-m-d');

            if ($openingChanged && $account->movements()->exists()) {
                throw ValidationException::withMessages(['opening_balance' => 'A conta já tem movimentações. Use um ajuste de saldo com justificativa.']);
            }

            $account->fill(['opening_balance' => $opening, 'opening_date' => $data['opening_date'] ?? $account->opening_date])->save();
            $this->audit->record('finance.account_updated', null, $user, $account);

            return $account;
        });
    }

    public function setActive(FinanceAccount $account, User $user, bool $isActive): void
    {
        $account->update(['is_active' => $isActive]);
        $this->audit->record($isActive ? 'finance.account_reactivated' : 'finance.account_deactivated', null, $user, $account);
    }

    /** Ajuste para o saldo informado: a diferença vira um lançamento de ajuste, fora do resultado. */
    public function adjust(FinanceAccount $account, User $user, string $targetBalance, string $date, string $reason, string $idempotencyKey): FinanceMovement
    {
        return DB::transaction(function () use ($account, $user, $targetBalance, $date, $reason, $idempotencyKey): FinanceMovement {
            $account = FinanceAccount::query()->lockForUpdate()->findOrFail($account->id);
            $existing = FinanceMovement::query()->where('company_id', $account->company_id)->where('idempotency_key', "adjustment:{$idempotencyKey}")->first();

            if ($existing !== null) {
                return $existing;
            }

            $difference = BigDecimal::of($targetBalance)->minus($this->ledger->balanceOf($account, $date));

            if ($difference->isZero()) {
                throw ValidationException::withMessages(['balance' => 'O saldo informado é igual ao saldo atual.']);
            }

            $movement = $this->ledger->record(new MovementEntry(
                companyId: $account->company_id,
                accountId: $account->id,
                type: MovementType::Adjustment,
                amount: (string) $difference,
                occurredAt: $date,
                description: "Ajuste de saldo: {$reason}",
                idempotencyKey: "adjustment:{$idempotencyKey}",
                user: $user,
            ));
            $this->audit->record('finance.balance_adjusted', null, $user, $movement, ['account' => $account->name, 'difference' => (string) $difference, 'reason' => $reason]);

            return $movement;
        });
    }
}

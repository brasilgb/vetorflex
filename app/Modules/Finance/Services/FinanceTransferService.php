<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Data\MovementEntry;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transferência entre contas: saída na origem e entrada no destino, com a mesma referência, fora do resultado.
 * O estorno gera os dois lançamentos opostos.
 */
class FinanceTransferService
{
    public function __construct(private FinanceLedger $ledger, private AuditService $audit) {}

    /** @param array{from_account_id: int, to_account_id: int, amount: string, transferred_at: string, description?: string|null, idempotency_key: string} $data */
    public function transfer(Company $company, User $user, array $data): FinanceTransfer
    {
        $hash = hash('sha256', (string) json_encode([(int) $data['from_account_id'], (int) $data['to_account_id'], CommercialMath::money($data['amount']), $data['transferred_at'], $data['description'] ?? null]));

        try {
            return DB::transaction(function () use ($company, $user, $data, $hash): FinanceTransfer {
                $existing = $this->existing($company, $data['idempotency_key'], $hash);

                if ($existing !== null) {
                    return $existing;
                }

                $ids = [(int) $data['from_account_id'], (int) $data['to_account_id']];
                $accounts = FinanceAccount::query()->where('company_id', $company->id)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $from = $accounts->get($ids[0]);
                $to = $accounts->get($ids[1]);

                if ($from === null || $to === null || $from->id === $to->id || ! $from->is_active || ! $to->is_active) {
                    throw ValidationException::withMessages(['to_account_id' => 'Selecione duas contas ativas e diferentes desta empresa.']);
                }

                $amount = BigDecimal::of($data['amount']);

                if (! $amount->isPositive()) {
                    throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
                }

                $transfer = FinanceTransfer::create([
                    'company_id' => $company->id,
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                    'amount' => CommercialMath::money($amount),
                    'transferred_at' => $data['transferred_at'],
                    'description' => $data['description'] ?? null,
                    'status' => 'confirmed',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'idempotency_key' => $data['idempotency_key'],
                    'request_hash' => $hash,
                ]);
                $label = $data['description'] ?? null ? " ({$data['description']})" : '';

                foreach ([[$from, $amount->negated(), "Transferência para {$to->name}{$label}", 'out'], [$to, $amount, "Transferência de {$from->name}{$label}", 'in']] as [$account, $value, $description, $leg]) {
                    $this->ledger->record(new MovementEntry(
                        companyId: $company->id,
                        accountId: $account->id,
                        type: MovementType::Transfer,
                        amount: (string) $value,
                        occurredAt: $data['transferred_at'],
                        description: $description,
                        idempotencyKey: "transfer:{$transfer->id}:{$leg}",
                        transferId: $transfer->id,
                        user: $user,
                    ));
                }

                $this->audit->record('finance.transfer_registered', $company, $user, $transfer, ['amount' => $transfer->amount]);

                return $transfer;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($company, $data['idempotency_key'], $hash) ?? throw $exception;
        }
    }

    public function reverse(FinanceTransfer $transfer, User $user, string $reason): FinanceTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $reason): FinanceTransfer {
            $transfer = FinanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status === 'reversed') {
                throw ValidationException::withMessages(['transfer' => 'Esta transferência já foi estornada.']);
            }

            $today = now(Company::query()->findOrFail($transfer->company_id)->timezone)->format('Y-m-d');

            foreach (FinanceMovement::query()->where('finance_transfer_id', $transfer->id)->whereNull('reversal_of_id')->orderBy('id')->get() as $movement) {
                $this->ledger->reverse($movement, $user, $reason, $today);
            }

            $transfer->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by_name' => $user->name, 'reversal_reason' => $reason]);
            $this->audit->record('finance.transfer_reversed', null, $user, $transfer, ['reason' => $reason]);

            return $transfer;
        });
    }

    private function existing(Company $company, string $key, string $hash): ?FinanceTransfer
    {
        $existing = FinanceTransfer::query()->where('company_id', $company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada em outra transferência. Recarregue a página.']);
        }

        return $existing;
    }
}

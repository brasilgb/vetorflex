<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Finance\Data\MovementEntry;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Enums\SettlementStatus;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Events\TitleSettled;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Baixas de títulos. Título, baixa e movimentação são gravados numa única transação, com o título bloqueado,
 * o que impede baixas concorrentes acima do saldo. Valor movimentado = principal + juros + multa − desconto.
 */
class FinanceSettlementService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private FinanceTitleService $titles,
        private FinanceLedger $ledger,
        private AuditService $audit,
    ) {}

    /**
     * Dados: finance_account_id (nulo só em baixa externa), settled_at, method, principal, interest, fine, discount,
     * notes, idempotency_key, is_external.
     *
     * @param  array<string, mixed>  $data
     */
    public function settle(FinanceTitle $title, ?User $user, array $data): FinanceSettlement
    {
        $values = [
            'principal' => CommercialMath::money($data['principal']),
            'interest' => CommercialMath::money($data['interest'] ?? '0'),
            'fine' => CommercialMath::money($data['fine'] ?? '0'),
            'discount' => CommercialMath::money($data['discount'] ?? '0'),
        ];
        $hash = hash('sha256', (string) json_encode([$title->id, $data['finance_account_id'] ?? null, $data['settled_at'], $data['method'] ?? null, $values, $data['notes'] ?? null, (bool) ($data['is_external'] ?? false)]));

        try {
            return DB::transaction(fn (): FinanceSettlement => $this->settleLocked($title, $user, $data, $values, $hash), self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($title->company_id, $data['idempotency_key'], $hash) ?? throw $exception;
        }
    }

    /** Estorno: movimentação compensatória; a baixa original permanece no histórico como "Estornada". */
    public function reverse(FinanceSettlement $settlement, User $user, string $reason): FinanceSettlement
    {
        return DB::transaction(function () use ($settlement, $user, $reason): FinanceSettlement {
            $title = $this->titles->lock($settlement->title);
            $settlement = FinanceSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($settlement->status === SettlementStatus::Reversed) {
                throw ValidationException::withMessages(['settlement' => 'Esta baixa já foi estornada.']);
            }

            $movement = FinanceMovement::query()->where('finance_settlement_id', $settlement->id)->whereNull('reversal_of_id')->first();

            if ($movement !== null) {
                $this->ledger->reverse($movement, $user, $reason, now($title->company->timezone)->format('Y-m-d'));
            }

            $settlement->update(['status' => SettlementStatus::Reversed, 'reversed_at' => now(), 'reversed_by_name' => $user->name, 'reversal_reason' => $reason]);
            $title->forceFill([
                'settled_principal' => CommercialMath::money(BigDecimal::of($title->settled_principal)->minus($settlement->principal)),
                'interest_total' => CommercialMath::money(BigDecimal::of($title->interest_total)->minus($settlement->interest)),
                'fine_total' => CommercialMath::money(BigDecimal::of($title->fine_total)->minus($settlement->fine)),
                'discount_total' => CommercialMath::money(BigDecimal::of($title->discount_total)->minus($settlement->discount)),
            ])->save();
            $this->titles->refreshStatus($title);
            $this->titles->event($title, 'reversed', "Estorno da baixa de {$settlement->settled_at->format('d/m/Y')}: {$reason}", $settlement->principal, $user);
            $this->audit->record('finance.settlement_reversed', $title->company, $user, $settlement, ['title' => $title->number, 'reason' => $reason]);
            TitleSettled::dispatch($title);

            return $settlement;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{principal: string, interest: string, fine: string, discount: string}  $values
     */
    private function settleLocked(FinanceTitle $title, ?User $user, array $data, array $values, string $hash): FinanceSettlement
    {
        $title = $this->titles->lock($title);
        $existing = $this->existing($title->company_id, $data['idempotency_key'], $hash);

        if ($existing !== null) {
            return $existing;
        }

        $isExternal = (bool) ($data['is_external'] ?? false);
        $principal = BigDecimal::of($values['principal']);
        $open = $title->openBalance();

        if (! $title->status->isOpen()) {
            throw ValidationException::withMessages(['principal' => "Título \"{$title->status->label()}\" não aceita baixa."]);
        }

        if (! $principal->isPositive() || $principal->isGreaterThan($open)) {
            throw ValidationException::withMessages(['principal' => 'O valor baixado deve ser maior que zero e não pode superar o saldo em aberto ('.CommercialMath::money($open).'). Para valores a mais, registre um título de crédito.']);
        }

        $amount = $principal->plus($values['interest'])->plus($values['fine'])->minus($values['discount']);

        if ($amount->isNegative() || BigDecimal::of($values['discount'])->isGreaterThan($principal->plus($values['interest'])->plus($values['fine']))) {
            throw ValidationException::withMessages(['discount' => 'O desconto não pode superar o valor da baixa.']);
        }

        $account = null;

        if (! $isExternal) {
            $account = FinanceAccount::query()->where('company_id', $title->company_id)->find((int) ($data['finance_account_id'] ?? 0));

            if ($account === null || ! $account->is_active) {
                throw ValidationException::withMessages(['finance_account_id' => 'Selecione uma conta financeira ativa.']);
            }
        }

        $settlement = FinanceSettlement::create([
            'company_id' => $title->company_id,
            'finance_title_id' => $title->id,
            'finance_account_id' => $account?->id,
            'status' => SettlementStatus::Confirmed,
            'is_external' => $isExternal,
            'settled_at' => $data['settled_at'],
            'method' => $data['method'] ?? null,
            ...$values,
            'amount' => CommercialMath::money($amount),
            'notes' => $data['notes'] ?? null,
            'user_id' => $user?->id,
            'user_name' => $user->name ?? 'Sistema',
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $hash,
        ]);

        if ($account !== null && $amount->isPositive()) {
            $this->ledger->record(new MovementEntry(
                companyId: $title->company_id,
                accountId: $account->id,
                type: $title->kind === TitleKind::Receivable ? MovementType::Entry : MovementType::Exit,
                amount: (string) $amount->multipliedBy($title->kind->sign()),
                occurredAt: $data['settled_at'],
                description: ($title->kind === TitleKind::Receivable ? 'Recebimento' : 'Pagamento')." do título #{$title->number}: {$title->description}",
                idempotencyKey: "settlement:{$settlement->id}",
                isOperational: true,
                categoryId: $title->finance_category_id,
                settlementId: $settlement->id,
                method: $data['method'] ?? null,
                user: $user,
            ));
        }

        $title->forceFill([
            'settled_principal' => CommercialMath::money(BigDecimal::of($title->settled_principal)->plus($principal)),
            'interest_total' => CommercialMath::money(BigDecimal::of($title->interest_total)->plus($values['interest'])),
            'fine_total' => CommercialMath::money(BigDecimal::of($title->fine_total)->plus($values['fine'])),
            'discount_total' => CommercialMath::money(BigDecimal::of($title->discount_total)->plus($values['discount'])),
        ])->save();
        $this->titles->refreshStatus($title);
        $this->titles->event($title, 'settled', ($isExternal ? 'Liquidação externa informada' : ($title->kind === TitleKind::Receivable ? 'Recebimento' : 'Pagamento')." em {$account->name}").($principal->isEqualTo($open) ? ' (integral).' : ' (parcial).'), $values['principal'], $user);
        $this->audit->record('finance.title_settled', $title->company, $user, $settlement, ['title' => $title->number, 'amount' => CommercialMath::money($amount)]);
        TitleSettled::dispatch($title);

        return $settlement;
    }

    private function existing(int $companyId, string $key, string $hash): ?FinanceSettlement
    {
        $existing = FinanceSettlement::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();

        if ($existing !== null && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta requisição já foi usada em outra baixa. Recarregue a página.']);
        }

        return $existing;
    }
}

<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Enums\CashMovementType;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Events\PosCashMovementRecorded;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sangrias, suprimentos e ajustes da gaveta. Cada lançamento é imutável e identifica operador, motivo e quem autorizou.
 * Não são receitas nem despesas; com o Financeiro configurado, sangria e suprimento viram transferências entre contas.
 * Depois do fechamento, só cabem correções (ajustes autorizados), registradas à parte do valor esperado.
 */
class PosCashService
{
    public function __construct(
        private PosSessionService $sessions,
        private PosAuthorizer $authorizer,
        private PosModule $module,
        private AuditService $audit,
    ) {}

    /**
     * @param  string  $amount  Positivo; no ajuste, o sinal indica entrada (+) ou saída (−).
     * @param  array{email?: string|null, password?: string|null}|null  $credentials
     */
    public function record(PosSession $session, User $operator, CashMovementType $type, string $amount, string $reason, ?array $credentials, string $idempotencyKey): PosCashMovement
    {
        $company = Company::query()->findOrFail($session->company_id);
        $isPostClose = ! $session->isOpen();

        if ($type === CashMovementType::Refund) {
            throw ValidationException::withMessages(['type' => 'Restituições são registradas pela devolução.']);
        }

        if ($isPostClose && $type !== CashMovementType::Adjustment) {
            throw ValidationException::withMessages(['type' => 'Caixa fechado: registre somente correções por ajuste autorizado.']);
        }

        if (! $isPostClose) {
            $this->sessions->assertOperable($session, $operator);
        }

        $value = BigDecimal::of(CommercialMath::money($amount));

        if ($value->isZero() || ($type !== CashMovementType::Adjustment && $value->isNegative())) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }

        $permission = match ($type) {
            CashMovementType::Supply => PosPermission::Supply,
            CashMovementType::Withdrawal => PosPermission::Withdraw,
            default => null,
        };
        $authorizer = $this->authorizer->authorize($company, $operator, $permission, $credentials, $type->label());

        try {
            return DB::transaction(function () use ($company, $session, $operator, $type, $value, $reason, $idempotencyKey, $authorizer, $isPostClose): PosCashMovement {
                $existing = PosCashMovement::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }

                $session = PosSession::query()->lockForUpdate()->findOrFail($session->id);

                if ($type === CashMovementType::Withdrawal && $value->isGreaterThan($this->sessions->summary($session)['expected_cash'])) {
                    throw ValidationException::withMessages(['amount' => 'A sangria supera o dinheiro esperado na gaveta ('.$this->sessions->summary($session)['expected_cash'].').']);
                }

                $movement = PosCashMovement::create([
                    'company_id' => $company->id,
                    'pos_session_id' => $session->id,
                    'type' => $type,
                    'amount' => CommercialMath::money($type === CashMovementType::Adjustment ? $value : $value->abs()),
                    'reason' => $reason,
                    'user_id' => $operator->id,
                    'user_name' => $operator->name,
                    'authorized_by' => $authorizer->id,
                    'authorized_by_name' => $authorizer->name,
                    'is_post_close' => $isPostClose,
                    'occurred_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->audit->record('pos.cash_'.$type->value, $company, $operator, $movement, ['session' => $session->number, 'amount' => $movement->amount, 'reason' => $reason, 'authorized_by' => $authorizer->name]);
                $settings = $this->module->settings($company);
                [$from, $to] = match ($type) {
                    CashMovementType::Withdrawal => [$settings->cashAccountId, $settings->treasuryAccountId],
                    CashMovementType::Supply => [$settings->treasuryAccountId, $settings->cashAccountId],
                    default => [null, null],
                };
                PosCashMovementRecorded::dispatch($company->id, $movement->id, $type->value, $movement->amount, now($company->timezone)->format('Y-m-d'), $reason, $operator->id, $from, $to);

                return $movement;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return PosCashMovement::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first() ?? throw $exception;
        }
    }
}

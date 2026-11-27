<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Pos\Enums\CashMovementType;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Enums\PosSessionStatus;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abertura, resumo e fechamento do caixa. O saldo inicial é troco na gaveta, nunca receita. Cada terminal tem no
 * máximo uma sessão aberta (índice único em `open_terminal_id`); depois do fechamento, nada é alterado diretamente.
 *
 * Dinheiro esperado = saldo inicial + recebido em dinheiro + suprimentos − sangrias − restituições em dinheiro ± ajustes.
 */
class PosSessionService
{
    public function __construct(
        private SequenceService $sequences,
        private PosAuthorizer $authorizer,
        private PosModule $module,
        private AuditService $audit,
    ) {}

    public function open(Company $company, User $user, PosTerminal $terminal, string $openingAmount, ?string $notes, string $idempotencyKey): PosSession
    {
        try {
            return DB::transaction(function () use ($company, $user, $terminal, $openingAmount, $notes, $idempotencyKey): PosSession {
                $existing = PosSession::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }

                $terminal = PosTerminal::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($terminal->id);

                if (! $terminal->is_active) {
                    throw ValidationException::withMessages(['pos_terminal_id' => 'Terminal inativo.']);
                }

                if (! $this->mayOperate($company, $terminal, $user)) {
                    throw ValidationException::withMessages(['pos_terminal_id' => 'Você não está autorizado a operar este terminal.']);
                }

                if (PosSession::query()->where('open_terminal_id', $terminal->id)->exists()) {
                    throw ValidationException::withMessages(['pos_terminal_id' => 'Já existe um caixa aberto neste terminal.']);
                }

                $session = PosSession::create([
                    'company_id' => $company->id,
                    'number' => $this->sequences->next($company, 'pos_session'),
                    'pos_terminal_id' => $terminal->id,
                    'open_terminal_id' => $terminal->id,
                    'status' => PosSessionStatus::Open,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'opening_amount' => CommercialMath::money($openingAmount),
                    'opening_notes' => $notes,
                    'opened_at' => now(),
                    'is_blind' => $this->module->settings($company)->blindClose,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->audit->record('pos.session_opened', $company, $user, $session, ['terminal' => $terminal->code, 'opening_amount' => $session->opening_amount]);

                return $session;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return PosSession::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first()
                ?? throw ValidationException::withMessages(['pos_terminal_id' => 'Já existe um caixa aberto neste terminal.']);
        }
    }

    /** Sem operadores vinculados, qualquer usuário com `pos.open_session`; com vínculos, somente eles (ou proprietários). */
    public function mayOperate(Company $company, PosTerminal $terminal, User $user): bool
    {
        if (! $this->authorizer->can($company, $user, PosPermission::OpenSession)) {
            return false;
        }

        $operators = $terminal->operators()->pluck('users.id');

        return $operators->isEmpty() || $operators->contains($user->id) || $this->authorizer->can($company, $user, PosPermission::ManageTerminals);
    }

    /** A sessão está aberta e pertence ao operador (ou ele pode autorizar operações). */
    public function assertOperable(PosSession $session, User $user): void
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages(['session' => 'Este caixa está fechado.']);
        }

        if ($session->user_id !== $user->id && ! $this->authorizer->can(Company::query()->findOrFail($session->company_id), $user, PosPermission::Authorize)) {
            throw ValidationException::withMessages(['session' => 'Este caixa foi aberto por outro operador.']);
        }
    }

    /**
     * @param  array{email?: string|null, password?: string|null}|null  $credentials
     */
    public function close(PosSession $session, User $user, string $countedCash, ?string $notes, ?array $credentials): PosSession
    {
        $company = Company::query()->findOrFail($session->company_id);
        $authorizer = $session->user_id === $user->id ? $user : $this->authorizer->authorize($company, $user, null, $credentials, 'Fechar o caixa de outro operador');

        return DB::transaction(function () use ($session, $user, $countedCash, $notes, $company, $authorizer): PosSession {
            $session = PosSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['session' => 'Este caixa já foi fechado.']);
            }

            $summary = $this->summary($session);
            $divergence = BigDecimal::of(CommercialMath::money($countedCash))->minus($summary['expected_cash']);

            if (! $divergence->isZero() && blank($notes)) {
                throw ValidationException::withMessages(['closing_notes' => 'Há divergência de '.CommercialMath::money($divergence).' no dinheiro. Informe a justificativa.']);
            }

            $session->update([
                'status' => PosSessionStatus::Closed,
                'open_terminal_id' => null,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'closed_by_name' => $authorizer->is($user) ? $user->name : "{$user->name} (autorizado por {$authorizer->name})",
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => CommercialMath::money($countedCash),
                'divergence' => CommercialMath::money($divergence),
                'closing_notes' => $notes,
                'summary' => $summary,
            ]);
            $this->audit->record('pos.session_closed', $company, $user, $session, ['expected' => $summary['expected_cash'], 'counted' => $session->counted_cash, 'divergence' => $session->divergence]);

            return $session;
        });
    }

    /**
     * Resumo calculado a partir dos registros da sessão. Correções feitas após o fechamento aparecem à parte.
     *
     * @return array{opening: string, sales_count: int, sales_total: string, received_total: string, term_total: string, change_total: string, by_method: list<array{method: string, label: string, received: string, term: string}>, supplies: string, withdrawals: string, adjustments: string, cash_refunds: string, refunds_total: string, returns_count: int, cancelled_count: int, suspended_count: int, cash_received: string, expected_cash: string, post_close_adjustments: string}
     */
    public function summary(PosSession $session): array
    {
        $completed = PosSale::query()->where('pos_session_id', $session->id)->where('status', PosSaleStatus::Completed);
        $payments = DB::table('pos_payments')->join('pos_sales', 'pos_sales.id', '=', 'pos_payments.pos_sale_id')
            ->where('pos_sales.pos_session_id', $session->id)->where('pos_sales.status', PosSaleStatus::Completed->value)
            ->groupBy('pos_payments.method', 'pos_payments.is_received')
            ->selectRaw('pos_payments.method, pos_payments.is_received, sum(pos_payments.amount) as total')->get();
        $movements = PosCashMovement::query()->where('pos_session_id', $session->id)
            ->groupBy('type', 'is_post_close')->selectRaw('type, is_post_close, sum(amount) as total')->toBase()->get();
        $sum = fn (string $type, bool $postClose = false): BigDecimal => BigDecimal::of((string) ($movements->first(fn (object $row): bool => $row->type === $type && (bool) $row->is_post_close === $postClose)->total ?? '0'));
        $byMethod = [];

        foreach ($payments as $row) {
            $method = PaymentMethod::tryFrom((string) $row->method);
            $key = (string) $row->method;
            $byMethod[$key] ??= ['method' => $key, 'label' => $method?->label() ?? $key, 'received' => BigDecimal::zero(), 'term' => BigDecimal::zero()];
            $byMethod[$key][(bool) $row->is_received ? 'received' : 'term'] = $byMethod[$key][(bool) $row->is_received ? 'received' : 'term']->plus((string) $row->total);
        }

        $cashReceived = isset($byMethod['cash']) ? $byMethod['cash']['received'] : BigDecimal::zero();
        $expected = BigDecimal::of($session->opening_amount)->plus($cashReceived)->plus($sum(CashMovementType::Supply->value))
            ->minus($sum(CashMovementType::Withdrawal->value))->minus($sum(CashMovementType::Refund->value))->plus($sum(CashMovementType::Adjustment->value));

        return [
            'opening' => CommercialMath::money($session->opening_amount),
            'sales_count' => (clone $completed)->count(),
            'sales_total' => CommercialMath::money((string) ((clone $completed)->sum('total') ?: '0')),
            'received_total' => CommercialMath::money((string) ((clone $completed)->sum('received_total') ?: '0')),
            'term_total' => CommercialMath::money((string) ((clone $completed)->sum('term_total') ?: '0')),
            'change_total' => CommercialMath::money((string) ((clone $completed)->sum('change_total') ?: '0')),
            'by_method' => array_values(array_map(fn (array $row): array => ['method' => $row['method'], 'label' => $row['label'], 'received' => CommercialMath::money($row['received']), 'term' => CommercialMath::money($row['term'])], $byMethod)),
            'supplies' => CommercialMath::money($sum(CashMovementType::Supply->value)),
            'withdrawals' => CommercialMath::money($sum(CashMovementType::Withdrawal->value)),
            'adjustments' => CommercialMath::money($sum(CashMovementType::Adjustment->value)),
            'cash_refunds' => CommercialMath::money($sum(CashMovementType::Refund->value)),
            'refunds_total' => CommercialMath::money((string) (PosRefund::query()->where('pos_session_id', $session->id)->sum('amount') ?: '0')),
            'returns_count' => PosRefund::query()->where('pos_session_id', $session->id)->count(),
            'cancelled_count' => PosSale::query()->where('pos_session_id', $session->id)->where('status', PosSaleStatus::Cancelled)->count(),
            'suspended_count' => PosSale::query()->where('pos_session_id', $session->id)->where('status', PosSaleStatus::Suspended)->count(),
            'cash_received' => CommercialMath::money($cashReceived),
            'expected_cash' => CommercialMath::money($expected),
            'post_close_adjustments' => CommercialMath::money($sum(CashMovementType::Adjustment->value, true)),
        ];
    }
}

<?php

namespace App\Modules\Finance\Listeners;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Enums\SettlementStatus;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Finance\Integrations\CommercialObligations;
use App\Modules\Finance\Integrations\ObligationReconciler;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceModule;
use App\Modules\Finance\Services\FinanceSettlementService;
use App\Modules\Payments\Events\PaymentReceived;
use App\Modules\Payments\Events\PaymentRefundConfirmed;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Integração opcional com Pagamentos, só pelos dados dos eventos:
 *
 * - pagamento confirmado de cobrança de conta a receber ou de venda: baixa dos títulos em aberto, na conta financeira
 *   configurada na conta do provedor, com chave determinística por transação (sem baixa duplicada). Cobranças do PDV
 *   são baixadas pela integração do PDV (`SyncPosOperations`), para não duplicar; cobranças avulsas não têm título;
 * - devolução confirmada: estorno das baixas da transação e nova baixa do valor que restou, se houver.
 *
 * Sem conta financeira configurada, nada é baixado (o pagamento continua registrado em Pagamentos). Falhas são
 * registradas e não interrompem Pagamentos.
 */
class SyncPaymentOperations
{
    public function __construct(
        private FinanceModule $module,
        private FinanceSettlementService $settlements,
        private SaleReceivableSource $receivables,
        private ObligationReconciler $reconciler,
    ) {}

    public function handle(PaymentReceived|PaymentRefundConfirmed $event): void
    {
        $company = Company::query()->find($event->companyId);

        if ($company === null || ! $this->module->isEnabled($company) || $event->financeAccountId === null
            || ! FinanceAccount::query()->where('company_id', $company->id)->whereKey($event->financeAccountId)->where('is_active', true)->exists()) {
            return;
        }

        try {
            $event instanceof PaymentReceived ? $this->settle($company, $event) : $this->refund($company, $event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function settle(Company $company, PaymentReceived $event): void
    {
        if ($event->origin === 'pos' || $event->originType === null || $event->originId === null) {
            return;
        }

        $user = $event->userId === null ? null : User::query()->find($event->userId);
        $this->distribute($company, $this->titles($company, $event->originType, $event->originId), BigDecimal::of($event->amount), $event->financeAccountId, $event->paidAt, $user, "payment-transaction:{$event->transactionId}", "Recebido por cobrança Pix (transação {$event->transactionId}).");
    }

    private function refund(Company $company, PaymentRefundConfirmed $event): void
    {
        $user = $event->userId === null ? null : User::query()->find($event->userId);
        $settlements = FinanceSettlement::query()->where('company_id', $company->id)->where('idempotency_key', 'like', "payment-transaction:{$event->transactionId}:%")
            ->where('status', SettlementStatus::Confirmed)->orderBy('id')->get();

        if ($settlements->isEmpty() || $user === null) {
            return;
        }

        $titles = FinanceTitle::query()->whereIn('id', $settlements->pluck('finance_title_id'))->orderBy('due_date')->orderBy('id')->get();

        foreach ($settlements as $settlement) {
            $this->settlements->reverse($settlement, $user, "Devolução confirmada pelo provedor (devolução {$event->refundId}).");
        }

        $this->distribute($company, $titles->map->refresh(), BigDecimal::of($event->transactionRemaining), $event->financeAccountId, now($company->timezone)->format('Y-m-d'), $user, "payment-transaction:{$event->transactionId}:after-refund:{$event->refundId}", 'Valor mantido após devolução parcial da cobrança Pix.');
    }

    /** @return Collection<int, FinanceTitle> */
    private function titles(Company $company, string $type, int $id): Collection
    {
        if ($type === 'finance_title') {
            return FinanceTitle::query()->where('company_id', $company->id)->where('kind', TitleKind::Receivable)->whereKey($id)->get();
        }

        if ($type !== 'sale_order') {
            return collect();
        }

        $order = SaleOrder::query()->where('company_id', $company->id)->find($id);
        $receivable = $order === null ? null : $this->receivables->receivableFor($order);

        if ($receivable !== null) {
            // Idempotente: garante os títulos da venda mesmo que a confirmação ainda não tenha sido processada.
            $this->reconciler->register(CommercialObligations::fromSale($receivable), null);
        }

        return FinanceTitle::query()->where('company_id', $company->id)->where('kind', TitleKind::Receivable)->where('source_type', 'sale_order')->where('source_id', $id)
            ->orderBy('due_date')->orderBy('installment_number')->orderBy('id')->get();
    }

    /** @param Collection<int, FinanceTitle> $titles */
    private function distribute(Company $company, Collection $titles, BigDecimal $remaining, int $accountId, string $date, ?User $user, string $keyPrefix, string $notes): void
    {
        foreach ($titles as $title) {
            $open = $title->openBalance();

            if (! $remaining->isPositive() || ! $title->status->isOpen() || ! $open->isPositive()) {
                continue;
            }

            $principal = BigDecimal::min($open, $remaining);
            $this->settlements->settle($title, $user, [
                'finance_account_id' => $accountId,
                'settled_at' => $date,
                'method' => 'pix',
                'principal' => CommercialMath::money($principal),
                'notes' => $notes,
                'idempotency_key' => "{$keyPrefix}:title:{$title->id}",
            ]);
            $remaining = $remaining->minus($principal);
        }
    }
}

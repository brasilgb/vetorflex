<?php

namespace App\Modules\Finance\Listeners;

use App\Models\User;
use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Integrations\CommercialObligations;
use App\Modules\Finance\Integrations\ObligationReconciler;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceModule;
use App\Modules\Finance\Services\FinanceSettlementService;
use App\Modules\Finance\Services\FinanceTransferService;
use App\Modules\Pos\Events\PosCashMovementRecorded;
use App\Modules\Pos\Events\PosRefundIssued;
use App\Modules\Pos\Events\PosSaleCompleted;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Throwable;

/**
 * Integração opcional com o PDV, só pelos dados dos eventos (sem ler tabelas do PDV):
 *
 * - venda concluída: os títulos vêm da integração com Vendas (uma única venda, sem duplicidade); aqui somente os
 *   pagamentos efetivamente recebidos recebem baixa, na conta configurada para a forma de pagamento. Valores a prazo,
 *   e formas sem conta configurada, ficam em aberto;
 * - restituição: baixa do título de restituição gerado pela devolução;
 * - sangria e suprimento: transferência entre as contas configuradas (caixa e tesouraria), fora do resultado.
 *
 * A abertura de caixa não gera nada. Chaves determinísticas evitam duplicidade, e falhas não interrompem o PDV.
 */
class SyncPosOperations
{
    public function __construct(
        private FinanceModule $module,
        private SaleReceivableSource $receivables,
        private ObligationReconciler $reconciler,
        private FinanceSettlementService $settlements,
        private FinanceTransferService $transfers,
    ) {}

    public function handle(PosSaleCompleted|PosRefundIssued|PosCashMovementRecorded $event): void
    {
        $company = Company::query()->find($event->companyId);

        if ($company === null || ! $this->module->isEnabled($company)) {
            return;
        }

        try {
            match (true) {
                $event instanceof PosSaleCompleted => $this->settleSale($company, $event),
                $event instanceof PosRefundIssued => $this->settleRefund($company, $event),
                default => $this->transfer($company, $event),
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function settleSale(Company $company, PosSaleCompleted $event): void
    {
        $order = SaleOrder::query()->where('company_id', $company->id)->find($event->saleOrderId);
        $receivable = $order === null ? null : $this->receivables->receivableFor($order);

        if ($receivable === null) {
            return;
        }

        // Idempotente: garante os títulos mesmo que a confirmação da venda ainda não tenha sido processada.
        $this->reconciler->register(CommercialObligations::fromSale($receivable), null);
        $user = $event->userId === null ? null : User::query()->find($event->userId);

        foreach ($event->payments as $payment) {
            if (! $payment['is_received'] || $payment['account_id'] === null || $payment['schedule_sequence'] === null || ! $this->accountIsUsable($company, $payment['account_id'])) {
                continue;
            }

            $title = FinanceTitle::query()->where('company_id', $company->id)->where('source_type', 'sale_order')->where('source_id', $order->id)
                ->where('source_key', "sale-order:{$order->id}:installment:{$payment['schedule_sequence']}")->first();
            $open = $title?->openBalance();

            if ($title === null || ! $title->status->isOpen() || $open === null || ! $open->isPositive()) {
                continue;
            }

            $this->settlements->settle($title, $user, [
                'finance_account_id' => $payment['account_id'],
                'settled_at' => $payment['date'],
                'method' => $payment['method'],
                'principal' => CommercialMath::money(BigDecimal::min($open, $payment['amount'])),
                'notes' => "Recebido no PDV (pagamento {$payment['sequence']}).",
                'idempotency_key' => "pos-payment:{$event->posSaleId}:{$payment['sequence']}",
            ]);
        }
    }

    private function settleRefund(Company $company, PosRefundIssued $event): void
    {
        if ($event->accountId === null || ! $this->accountIsUsable($company, $event->accountId)) {
            return;
        }

        $user = $event->userId === null ? null : User::query()->find($event->userId);
        $remaining = BigDecimal::of($event->amount);
        $titles = FinanceTitle::query()->where('company_id', $company->id)->where('source_type', 'sale_order')->where('source_id', $event->saleOrderId)
            ->where('origin', TitleOrigin::SaleRefund)->orderBy('id')->get();

        foreach ($titles as $title) {
            $open = $title->openBalance();

            if (! $remaining->isPositive() || ! $title->status->isOpen() || ! $open->isPositive()) {
                continue;
            }

            $principal = BigDecimal::min($open, $remaining);
            $this->settlements->settle($title, $user, [
                'finance_account_id' => $event->accountId,
                'settled_at' => $event->date,
                'method' => $event->method,
                'principal' => CommercialMath::money($principal),
                'notes' => 'Restituição paga no PDV.',
                'idempotency_key' => "pos-refund:{$event->refundId}:{$title->id}",
            ]);
            $remaining = $remaining->minus($principal);
        }
    }

    private function transfer(Company $company, PosCashMovementRecorded $event): void
    {
        $user = $event->userId === null ? null : User::query()->find($event->userId);

        if ($user === null || $event->fromAccountId === null || $event->toAccountId === null || $event->fromAccountId === $event->toAccountId
            || ! $this->accountIsUsable($company, $event->fromAccountId) || ! $this->accountIsUsable($company, $event->toAccountId)) {
            return;
        }

        $this->transfers->transfer($company, $user, [
            'from_account_id' => $event->fromAccountId,
            'to_account_id' => $event->toAccountId,
            'amount' => $event->amount,
            'transferred_at' => $event->date,
            'description' => ($event->type === 'withdrawal' ? 'Sangria do PDV' : 'Suprimento do PDV').": {$event->reason}",
            'idempotency_key' => "pos-cash:{$event->movementId}",
        ]);
    }

    private function accountIsUsable(Company $company, int $accountId): bool
    {
        return FinanceAccount::query()->where('company_id', $company->id)->whereKey($accountId)->where('is_active', true)->exists();
    }
}

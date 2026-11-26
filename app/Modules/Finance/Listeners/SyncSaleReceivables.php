<?php

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Integrations\CommercialObligations;
use App\Modules\Finance\Integrations\ObligationReconciler;
use App\Modules\Finance\Services\FinanceModule;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Sales\Events\SaleBalanceClosed;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleConfirmed;
use App\Modules\Sales\Events\SaleFulfillmentReversed;
use App\Modules\Sales\Events\SaleReturnRegistered;
use Throwable;

/**
 * Integração opcional com Vendas pelos eventos publicados e pelo contrato `SaleReceivableSource`.
 * A confirmação gera os títulos previstos; os demais eventos só ajustam títulos já existentes, de modo que
 * vendas anteriores à contratação do Financeiro entram apenas pela importação assistida.
 * Uma falha aqui é registrada e não interrompe Vendas: a importação permite reprocessar.
 */
class SyncSaleReceivables
{
    public function __construct(
        private FinanceModule $module,
        private SaleReceivableSource $receivables,
        private ObligationReconciler $reconciler,
    ) {}

    public function handle(SaleConfirmed|SaleCancelled|SaleReturnRegistered|SaleBalanceClosed|SaleFulfillmentReversed $event): void
    {
        $order = $event->order->fresh();

        if ($order === null || ! $this->module->isEnabled($order->company)) {
            return;
        }

        try {
            $receivable = $this->receivables->receivableFor($order);

            if ($receivable === null) {
                return;
            }

            $obligation = CommercialObligations::fromSale($receivable);

            match (true) {
                $event instanceof SaleConfirmed => $this->reconciler->register($obligation, null),
                $event instanceof SaleCancelled => $this->reconciler->reconcile($obligation, "venda cancelada ({$event->reason})."),
                $event instanceof SaleReturnRegistered => $this->reconciler->reconcile($obligation, "devolução #{$event->return->number}."),
                $event instanceof SaleBalanceClosed => $this->reconciler->reconcile($obligation, 'saldo não atendido encerrado.'),
                default => $this->reconciler->reconcile($obligation, 'atendimento estornado.'),
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

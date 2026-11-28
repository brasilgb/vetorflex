<?php

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Integrations\CommercialObligations;
use App\Modules\Finance\Integrations\ObligationReconciler;
use App\Modules\Finance\Services\FinanceModule;
use App\Modules\Purchases\Contracts\PurchaseObligationSource;
use App\Modules\Purchases\Events\PurchaseBalanceClosed;
use App\Modules\Purchases\Events\PurchaseCancelled;
use App\Modules\Purchases\Events\PurchaseConfirmed;
use App\Modules\Purchases\Events\PurchaseReceiptReversed;
use App\Modules\Purchases\Events\PurchaseReturnRegistered;
use Throwable;

/**
 * Integração opcional com Compras pelos eventos e pelo contrato `PurchaseObligationSource`.
 * Recebimento de mercadoria não é pagamento: só importa quando encerra o saldo do pedido.
 */
class SyncPurchaseObligations
{
    public function __construct(
        private FinanceModule $module,
        private PurchaseObligationSource $obligations,
        private ObligationReconciler $reconciler,
    ) {}

    public function handle(PurchaseConfirmed|PurchaseCancelled|PurchaseReturnRegistered|PurchaseBalanceClosed|PurchaseReceiptReversed $event): void
    {
        $order = $event->order->fresh();

        if ($order === null || ! $this->module->isEnabled($order->company)) {
            return;
        }

        try {
            $obligation = $this->obligations->obligationFor($order);

            if ($obligation === null) {
                return;
            }

            $mapped = CommercialObligations::fromPurchase($obligation);

            match (true) {
                $event instanceof PurchaseConfirmed => $this->reconciler->register($mapped, null),
                $event instanceof PurchaseCancelled => $this->reconciler->reconcile($mapped, 'compra cancelada.'),
                $event instanceof PurchaseReturnRegistered => $this->reconciler->reconcile($mapped, 'devolução ao fornecedor.'),
                $event instanceof PurchaseBalanceClosed => $this->reconciler->reconcile($mapped, 'saldo pendente encerrado; vale o valor recebido.'),
                default => $this->reconciler->reconcile($mapped, 'recebimento estornado.'),
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

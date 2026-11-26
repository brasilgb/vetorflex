<?php

namespace App\Modules\Purchases\Events;

use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido de compra confirmado: passa a gerar obrigação (PurchaseObligationSource). Publicado somente após a confirmação da transação.
 */
class PurchaseConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseOrder $order) {}
}

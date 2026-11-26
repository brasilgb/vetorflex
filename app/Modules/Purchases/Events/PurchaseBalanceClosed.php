<?php

namespace App\Modules\Purchases\Events;

use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Saldo pendente do pedido de compra encerrado. Publicado somente após a confirmação da transação.
 */
class PurchaseBalanceClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseOrder $order) {}
}

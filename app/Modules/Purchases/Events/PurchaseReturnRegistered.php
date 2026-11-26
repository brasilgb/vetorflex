<?php

namespace App\Modules\Purchases\Events;

use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Devolução de compra registrada. Publicado somente após a confirmação da transação.
 */
class PurchaseReturnRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseOrder $order) {}
}

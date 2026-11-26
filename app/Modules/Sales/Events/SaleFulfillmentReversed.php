<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Atendimento estornado tecnicamente; um saldo encerrado pode ter sido reaberto. Publicado somente após a confirmação da transação.
 */
class SaleFulfillmentReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order) {}
}

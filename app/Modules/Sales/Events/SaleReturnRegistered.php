<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Devolução comercial registrada. Publicado somente após a confirmação da transação.
 */
class SaleReturnRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order, public SaleReturn $return) {}
}

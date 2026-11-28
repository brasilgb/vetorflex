<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Atendimento integral ou parcial registrado. Publicado somente após a confirmação da transação.
 */
class SaleFulfilled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order, public SaleFulfillment $fulfillment) {}
}

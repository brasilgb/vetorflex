<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Venda cancelada; reservas de estoque já liberadas. Publicado somente após a confirmação da transação.
 */
class SaleCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order, public string $reason) {}
}

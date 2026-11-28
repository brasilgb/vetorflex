<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Saldo não atendido encerrado; o valor dos itens não atendidos deixa de ser devido. Publicado somente após a confirmação da transação.
 */
class SaleBalanceClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order) {}
}

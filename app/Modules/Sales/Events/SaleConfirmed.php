<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Venda confirmada: passa a gerar previsão de recebimento (SaleReceivableSource). Publicado somente após a confirmação da transação.
 */
class SaleConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order) {}
}

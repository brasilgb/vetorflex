<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Venda concluída. Não indica pagamento recebido. Publicado somente após a confirmação da transação.
 */
class SaleCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleOrder $order) {}
}

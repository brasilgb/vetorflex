<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Orçamento convertido em pedido de venda. Publicado somente após a confirmação da transação.
 */
class QuoteConverted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleQuote $quote, public SaleOrder $order) {}
}

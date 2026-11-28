<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleQuote;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Orçamento aprovado pelo cliente. Publicado somente após a confirmação da transação.
 */
class QuoteApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SaleQuote $quote) {}
}

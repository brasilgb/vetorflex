<?php

namespace App\Modules\Purchases\Events;

use App\Modules\Purchases\Models\PurchaseOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Recebimento estornado; um saldo encerrado pode ter sido reaberto. Publicado somente após a confirmação da transação.
 */
class PurchaseReceiptReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PurchaseOrder $order) {}
}

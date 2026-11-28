<?php

namespace App\Modules\Pos\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Restituição paga ao cliente no caixa, após uma devolução. Publicado somente após a confirmação da transação.
 */
class PosRefundIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $refundId,
        public int $saleOrderId,
        public string $method,
        public string $amount,
        public string $date,
        public ?int $userId,
        public ?int $accountId = null,
    ) {}
}

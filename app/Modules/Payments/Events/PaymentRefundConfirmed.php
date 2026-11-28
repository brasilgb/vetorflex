<?php

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Devolução confirmada pelo provedor, publicada uma única vez, após o commit. O Financeiro compensa as baixas da
 * transação devolvida.
 */
class PaymentRefundConfirmed
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $chargeId,
        public int $transactionId,
        public int $refundId,
        public string $amount,
        public string $transactionRemaining,
        public ?int $financeAccountId,
        public ?int $userId,
    ) {}
}

<?php

namespace App\Modules\Payments\Data;

/**
 * Pagamento recebido, como informado pelo provedor na consulta da cobrança.
 */
readonly class ProviderPayment
{
    /** @param list<ProviderRefund> $refunds */
    public function __construct(
        public string $id,
        public string $amount,
        public string $paidAt,
        public ?string $payerName = null,
        public ?string $payerDocument = null,
        public ?string $fee = null,
        public array $refunds = [],
    ) {}
}

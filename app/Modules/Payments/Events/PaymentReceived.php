<?php

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pagamento confirmado pelo provedor, publicado uma única vez por transação, após o commit. Consumido
 * opcionalmente pelo Financeiro (baixa do título) e pelo PDV (confirmação do Pix).
 */
class PaymentReceived
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $chargeId,
        public int $transactionId,
        public string $amount,
        public string $paidAt,
        public string $origin,
        public ?string $originType,
        public ?int $originId,
        public ?int $financeAccountId,
        public ?int $userId,
    ) {}
}

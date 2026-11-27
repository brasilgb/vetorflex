<?php

namespace App\Modules\Payments\Data;

/**
 * Cobrança como o provedor a vê: identificador, estado do provedor, dados do Pix e pagamentos recebidos. `isRemoved`
 * indica cancelamento ou remoção no provedor; a situação interna é calculada a partir dos pagamentos.
 */
readonly class ProviderCharge
{
    /** @param list<ProviderPayment> $payments */
    public function __construct(
        public string $id,
        public string $status,
        public bool $isRemoved = false,
        public ?string $pixCopyPaste = null,
        public ?string $qrCodeImage = null,
        public ?string $paymentUrl = null,
        public ?int $locationId = null,
        public array $payments = [],
    ) {}
}

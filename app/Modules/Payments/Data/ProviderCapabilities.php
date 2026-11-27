<?php

namespace App\Modules\Payments\Data;

/**
 * Operações efetivamente implementadas por um provedor. A interface só oferece o que estiver declarado aqui.
 */
readonly class ProviderCapabilities
{
    public function __construct(
        public bool $pix = false,
        public bool $boleto = false,
        public bool $creditCard = false,
        public bool $paymentLink = false,
        public bool $query = false,
        public bool $cancel = false,
        public bool $refund = false,
        public bool $webhooks = false,
    ) {}

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'pix' => $this->pix,
            'boleto' => $this->boleto,
            'credit_card' => $this->creditCard,
            'payment_link' => $this->paymentLink,
            'query' => $this->query,
            'cancel' => $this->cancel,
            'refund' => $this->refund,
            'webhooks' => $this->webhooks,
        ];
    }

    /** @return list<string> Formas de pagamento disponíveis. */
    public function methods(): array
    {
        return array_keys(array_filter(['pix' => $this->pix, 'boleto' => $this->boleto, 'credit_card' => $this->creditCard]));
    }
}

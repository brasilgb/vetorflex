<?php

namespace App\Modules\Purchases\Data;

/**
 * Obrigação comercial de um pedido de compra, no formato que o futuro módulo Financeiro consumirá para gerar
 * contas a pagar (OMNICHANNEL-06). Não representa pagamento nem título financeiro.
 */
readonly class PurchaseObligation
{
    /**
     * @param  list<array{sequence: int, due_date: string, amount: string, method: string|null, is_entry: bool}>  $installments
     */
    public function __construct(
        public int $companyId,
        public int $supplierProfileId,
        public int $partyId,
        public int $purchaseOrderId,
        public int $purchaseOrderNumber,
        public string $status,
        public string $amount,
        public string $receivedValue,
        public string $returnedValue,
        public array $installments,
        public string $origin,
        public string $idempotencyKey,
    ) {}
}

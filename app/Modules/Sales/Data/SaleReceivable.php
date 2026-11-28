<?php

namespace App\Modules\Sales\Data;

/**
 * Previsão de recebimento de uma venda, no formato que o Financeiro consumirá para gerar contas a receber.
 * Não representa pagamento recebido nem título financeiro.
 */
readonly class SaleReceivable
{
    /**
     * @param  list<array{sequence: int, due_date: string, amount: string, method: string|null, is_entry: bool, idempotency_key: string}>  $installments
     * @param  list<array{number: int, returned_at: string, amount: string, idempotency_key: string}>  $returns
     * @param  string  $closedBalanceValue  Valor líquido dos itens não atendidos quando o saldo foi encerrado; deixa de ser devido.
     */
    public function __construct(
        public int $companyId,
        public ?int $customerPartyId,
        public ?string $customerName,
        public int $saleOrderId,
        public int $saleOrderNumber,
        public string $status,
        public bool $isCancelled,
        public string $amount,
        public string $fulfilledValue,
        public string $returnedValue,
        public string $paymentCondition,
        public array $installments,
        public array $returns,
        public string $origin,
        public string $idempotencyKey,
        public string $closedBalanceValue = '0.00',
        public string $orderDate = '',
    ) {}
}

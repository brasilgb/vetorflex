<?php

namespace App\Modules\Finance\Integrations;

use App\Modules\Core\Commerce\CommercialMath;
use App\Modules\Finance\Data\CommercialObligation;
use App\Modules\Finance\Enums\TitleKind;
use App\Modules\Purchases\Data\PurchaseObligation;
use App\Modules\Sales\Data\SaleReceivable;
use Brick\Math\BigDecimal;

/**
 * Tradução dos contratos públicos de Vendas e Compras para o formato neutro do Financeiro.
 *
 * Venda: devido = total − devoluções − itens não atendidos com saldo encerrado; cancelada, nada é devido.
 * Compra: devido = total (ou o valor recebido, com saldo encerrado) − devoluções; cancelada, nada é devido.
 * Recebimento de mercadoria não é pagamento: só define quanto é devido quando o saldo é encerrado.
 */
class CommercialObligations
{
    public static function fromSale(SaleReceivable $receivable): CommercialObligation
    {
        $target = $receivable->isCancelled ? BigDecimal::zero() : BigDecimal::of($receivable->amount)->minus($receivable->returnedValue)->minus($receivable->closedBalanceValue);

        return new CommercialObligation(
            companyId: $receivable->companyId,
            kind: TitleKind::Receivable,
            sourceType: 'sale_order',
            sourceId: $receivable->saleOrderId,
            label: "Venda #{$receivable->saleOrderNumber}",
            partyId: $receivable->customerPartyId,
            partyName: $receivable->customerName ?? 'Consumidor não identificado',
            documentDate: $receivable->orderDate,
            amount: $receivable->amount,
            target: CommercialMath::money(BigDecimal::max(BigDecimal::zero(), $target)),
            isCancelled: $receivable->isCancelled,
            installments: array_map(fn (array $row): array => ['key' => $row['idempotency_key'], 'due_date' => $row['due_date'], 'amount' => $row['amount']], $receivable->installments),
            baseKey: $receivable->idempotencyKey,
        );
    }

    public static function fromPurchase(PurchaseObligation $obligation): CommercialObligation
    {
        $isCancelled = $obligation->status === 'cancelled';
        $base = $obligation->isBalanceClosed ? $obligation->receivedValue : $obligation->amount;
        $target = $isCancelled ? BigDecimal::zero() : BigDecimal::of($base)->minus($obligation->returnedValue);

        return new CommercialObligation(
            companyId: $obligation->companyId,
            kind: TitleKind::Payable,
            sourceType: 'purchase_order',
            sourceId: $obligation->purchaseOrderId,
            label: "Compra #{$obligation->purchaseOrderNumber}",
            partyId: $obligation->partyId,
            partyName: $obligation->supplierName,
            documentDate: $obligation->orderDate,
            amount: $obligation->amount,
            target: CommercialMath::money(BigDecimal::max(BigDecimal::zero(), $target)),
            isCancelled: $isCancelled,
            installments: array_map(fn (array $row): array => ['key' => "{$obligation->idempotencyKey}:installment:{$row['sequence']}", 'due_date' => $row['due_date'], 'amount' => $row['amount']], $obligation->installments),
            baseKey: $obligation->idempotencyKey,
        );
    }
}

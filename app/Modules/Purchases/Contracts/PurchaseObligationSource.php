<?php

namespace App\Modules\Purchases\Contracts;

use App\Modules\Purchases\Data\PurchaseObligation;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Tenancy\Models\Company;

/**
 * Contrato de leitura para o Financeiro. A obrigação pode nascer na confirmação do pedido (compra a prazo
 * negociada) ou no recebimento; a escolha pertence ao Financeiro e não altera Compras.
 */
interface PurchaseObligationSource
{
    /** Nulo para rascunhos: pedidos não confirmados não geram obrigação. */
    public function obligationFor(PurchaseOrder $order): ?PurchaseObligation;

    /**
     * Pedidos confirmados (inclusive cancelados depois) com data no período, para a importação assistida.
     *
     * @return list<PurchaseObligation>
     */
    public function obligationsBetween(Company $company, string $from, string $until): array;
}

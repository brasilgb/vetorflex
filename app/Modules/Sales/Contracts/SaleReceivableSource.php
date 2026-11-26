<?php

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Data\SaleReceivable;
use App\Modules\Sales\Models\SaleOrder;

/**
 * Contrato de leitura para o Financeiro (OMNICHANNEL-06). O Financeiro persiste e gere as contas a receber;
 * Vendas nunca escreve em tabelas financeiras.
 */
interface SaleReceivableSource
{
    /** Nulo para rascunhos: vendas não confirmadas não geram previsão de recebimento. */
    public function receivableFor(SaleOrder $order): ?SaleReceivable;
}

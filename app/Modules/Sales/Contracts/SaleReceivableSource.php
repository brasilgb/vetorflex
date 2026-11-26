<?php

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Data\SaleReceivable;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;

/**
 * Contrato de leitura para o Financeiro (OMNICHANNEL-06). O Financeiro persiste e gere as contas a receber;
 * Vendas nunca escreve em tabelas financeiras.
 */
interface SaleReceivableSource
{
    /** Nulo para rascunhos: vendas não confirmadas não geram previsão de recebimento. */
    public function receivableFor(SaleOrder $order): ?SaleReceivable;

    /**
     * Vendas confirmadas (inclusive canceladas depois) com data no período, para a importação assistida.
     *
     * @return list<SaleReceivable>
     */
    public function receivablesBetween(Company $company, string $from, string $until): array;
}

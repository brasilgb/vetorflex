<?php

namespace App\Modules\Purchases\Support;

use App\Modules\Core\Support\CsvFormat;

/**
 * Formato único de importação e exportação de fornecedores. Também grava as exportações de pedidos.
 */
class SupplierCsv extends CsvFormat
{
    /** Colunas aceitas na importação. */
    public const COLUMNS = [
        'nome', 'tipo', 'nome_fantasia', 'documento', 'inscricao_estadual', 'inscricao_municipal', 'email', 'telefone', 'celular',
        'whatsapp', 'codigo_interno', 'prazo_entrega_dias', 'condicao_pagamento', 'pedido_minimo', 'observacoes',
    ];

    /** Colunas da exportação que a importação reconhece, mas não grava. */
    public const READ_ONLY_COLUMNS = ['situacao', 'tambem_cliente'];

    protected const MAXIMUM_ROWS_CONFIG = 'purchases.import.maximum_rows';
}

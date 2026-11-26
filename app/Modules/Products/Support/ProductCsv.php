<?php

namespace App\Modules\Products\Support;

use App\Modules\Core\Support\CsvFormat;

/**
 * Formato único de importação e exportação de produtos.
 */
class ProductCsv extends CsvFormat
{
    /** Colunas aceitas na importação. */
    public const COLUMNS = [
        'nome', 'sku', 'codigo_interno', 'codigo_barras', 'tipo_codigo', 'categoria', 'marca', 'unidade',
        'referencia_fabricante', 'descricao_curta', 'descricao', 'preco_custo', 'preco_venda', 'preco_promocional',
        'estoque_minimo', 'controla_estoque', 'estoque_inicial', 'ncm', 'cest', 'origem', 'observacoes',
    ];

    /** Colunas da exportação que a importação reconhece, mas não grava. */
    public const READ_ONLY_COLUMNS = ['tipo', 'variacao', 'saldo', 'situacao'];

    protected const MAXIMUM_ROWS_CONFIG = 'products.import.maximum_rows';
}

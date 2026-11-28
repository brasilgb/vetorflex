<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Core\Support\CsvFormat;

/**
 * Importação e exportação de mercadorias do Estoque (UTF-8 com BOM, ponto e vírgula).
 */
class StockCsv extends CsvFormat
{
    public const COLUMNS = ['nome', 'sku', 'codigo_barras', 'codigo_interno', 'categoria', 'unidade', 'estoque_minimo', 'estoque_maximo', 'custo', 'preco_venda', 'saldo_inicial', 'deposito'];

    public const READ_ONLY_COLUMNS = ['saldo', 'reservado', 'situacao'];

    protected const MAXIMUM_ROWS_CONFIG = 'inventory.import.maximum_rows';
}

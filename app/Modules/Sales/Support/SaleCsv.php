<?php

namespace App\Modules\Sales\Support;

use App\Modules\Core\Support\CsvFormat;

/**
 * Exportação de vendas: UTF-8 com BOM, ponto e vírgula e proteção contra injeção de fórmulas.
 */
class SaleCsv extends CsvFormat {}

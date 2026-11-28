<?php

return [
    'import' => [
        // Limite de linhas por arquivo na importação de mercadorias do Estoque.
        'maximum_rows' => (int) env('INVENTORY_IMPORT_MAXIMUM_ROWS', 1000),
    ],

    // Dias sem movimentação para considerar uma mercadoria parada (padrão dos indicadores).
    'idle_days' => (int) env('INVENTORY_IDLE_DAYS', 60),
];

<?php

return [
    'spedy' => [
        // Rotas base da API v1 da Spedy (docs.spedy.com.br). Sandbox e produção são contas separadas.
        'base_urls' => [
            'sandbox' => 'https://sandbox-api.spedy.com.br/v1',
            'production' => 'https://api.spedy.com.br/v1',
        ],
        'timeout' => (int) env('FISCAL_SPEDY_TIMEOUT', 30),
        // Tolerância de horário da assinatura do webhook (Standard Webhooks), em segundos.
        'webhook_tolerance' => 300,
    ],

    // Disco privado dos XML e PDF (config/filesystems.php, disco `fiscal`).
    'disk' => env('FISCAL_DISK', 'fiscal'),

    // Prazo sem confirmação após o envio: o documento passa a "Falha de comunicação" e pode ser reenviado com o
    // mesmo identificador (a consulta pelo identificador vem antes).
    'unconfirmed_after_minutes' => (int) env('FISCAL_UNCONFIRMED_AFTER_MINUTES', 30),
];

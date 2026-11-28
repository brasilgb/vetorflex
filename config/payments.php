<?php

return [
    'webhooks' => [
        // Confere o IP de origem das notificações. A Efí documenta 34.193.116.226 como IP atual das comunicações.
        'enforce_ips' => (bool) env('PAYMENTS_WEBHOOK_ENFORCE_IPS', env('APP_ENV') === 'production'),
        'allowed_ips' => [
            'efi' => array_filter(explode(',', (string) env('PAYMENTS_EFI_WEBHOOK_IPS', '34.193.116.226'))),
        ],
    ],

    // Conciliação periódica: cobranças em aberto consultadas por execução.
    'reconcile_batch' => (int) env('PAYMENTS_RECONCILE_BATCH', 200),
];

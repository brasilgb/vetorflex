<?php

return [
    // Período de teste concedido no cadastro (dias), controlado pelo servidor.
    'trial_days' => (int) env('COMMERCIAL_TRIAL_DAYS', 14),

    // Versão dos termos de uso e da política de privacidade aceita no cadastro.
    'terms_version' => '2026-11',

    // Contato comercial exibido na página pública. Sem valor configurado, só o formulário de contato aparece.
    'contact' => [
        'email' => env('COMMERCIAL_CONTACT_EMAIL'),
        'whatsapp' => env('COMMERCIAL_CONTACT_WHATSAPP'),
    ],

    'company' => [
        'name' => 'ABrasilSistemas',
        'product' => 'VetorFlex',
    ],
];

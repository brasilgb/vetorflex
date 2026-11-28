<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizonte das recorrências, em dias
    |--------------------------------------------------------------------------
    |
    | Títulos recorrentes são gerados com antecedência até esta quantidade de
    | dias a partir de hoje, para aparecerem no fluxo de caixa previsto.
    |
    */

    'recurrence_horizon_days' => (int) env('FINANCE_RECURRENCE_HORIZON_DAYS', 31),

    /*
    |--------------------------------------------------------------------------
    | Anexos
    |--------------------------------------------------------------------------
    */

    'attachments' => [
        'disk' => env('FINANCE_ATTACHMENTS_DISK', 'local'),
        'maximum_kilobytes' => (int) env('FINANCE_ATTACHMENT_MAXIMUM_KILOBYTES', 5120),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml', 'txt'],
    ],

];

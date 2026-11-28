<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Venda sem identificação do cliente
    |--------------------------------------------------------------------------
    |
    | Padrão da plataforma. Cada empresa pode sobrescrever em
    | companies.settings['sales']['allow_anonymous_customer'].
    |
    */

    'allow_anonymous_customer' => (bool) env('SALES_ALLOW_ANONYMOUS_CUSTOMER', true),

    /*
    |--------------------------------------------------------------------------
    | Validade padrão do orçamento, em dias
    |--------------------------------------------------------------------------
    */

    'quote_validity_days' => (int) env('SALES_QUOTE_VALIDITY_DAYS', 15),

];

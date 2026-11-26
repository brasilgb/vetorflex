<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supplier Import
    |--------------------------------------------------------------------------
    |
    | Imports run in a single synchronous request, so these limits should
    | stay compatible with the request timeout.
    |
    */

    'import' => [
        'maximum_kilobytes' => (int) env('SUPPLIER_IMPORT_MAXIMUM_KILOBYTES', 2048),
        'maximum_rows' => (int) env('SUPPLIER_IMPORT_MAXIMUM_ROWS', 500),
    ],

];

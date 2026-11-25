<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Images
    |--------------------------------------------------------------------------
    |
    | Images are stored on a private disk and only served through an
    | authenticated route. Dimensions are limited because the images
    | are decoded in memory before being optimized and stored.
    |
    */

    'images' => [
        'disk' => env('PRODUCT_IMAGES_DISK', 'local'),
        'maximum_kilobytes' => 5120,
        'maximum_source_dimension' => 4096,
        'maximum_dimension' => 1600,
        'maximum_per_product' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Import
    |--------------------------------------------------------------------------
    |
    | Imports run in a single synchronous request, inside one transaction,
    | so these limits should stay compatible with the request timeout.
    |
    */

    'import' => [
        'maximum_kilobytes' => (int) env('PRODUCT_IMPORT_MAXIMUM_KILOBYTES', 2048),
        'maximum_rows' => (int) env('PRODUCT_IMPORT_MAXIMUM_ROWS', 500),
    ],

];

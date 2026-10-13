<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Token idle lifetime
    |--------------------------------------------------------------------------
    |
    | Days without use after which an access token stops authenticating
    | (measured from last_used_at, or created_at if never used). A token used
    | regularly never expires. For an absolute lifetime since creation, set
    | the Sanctum `expiration` (minutes) in config/sanctum.php.
    |
    */

    'tokens' => [
        'idle_days' => (int) env('MEMRY_TOKEN_IDLE_DAYS', 30),
    ],

];

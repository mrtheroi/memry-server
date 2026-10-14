<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Only the private dashboard SPA is a browser client, so the only allowed origins are the ones listed in
| MEMRY_DASHBOARD_URL (comma-separated, for example "https://app.memry.com.mx"). Empty by default: with no
| dashboard configured no browser origin is allowed. Credentials (the session cookie) are on, so a wildcard
| origin must never be used; browsers refuse it and it would be unsafe anyway.
|
| The CLI and MCP clients send no Origin and are not affected.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Exact origins are matched through anchored patterns on purpose: with a single entry in allowed_origins the
    // CORS library answers every origin with that one origin instead of omitting the header.
    'allowed_origins' => [],

    'allowed_origins_patterns' => array_values(array_map(
        fn (string $origin) => '#^'.preg_quote($origin, '#').'$#i',
        array_filter(array_map(
            fn (string $origin) => rtrim(trim($origin), '/'),
            explode(',', (string) env('MEMRY_DASHBOARD_URL', '')),
        )),
    )),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

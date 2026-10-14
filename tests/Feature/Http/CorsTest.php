<?php

const CORS_DASHBOARD = 'https://app.memry.test';

/**
 * Evaluates config/cors.php with MEMRY_DASHBOARD_URL set, so the tests exercise the real env-to-config mapping.
 */
function corsConfigFor(?string $dashboardUrl): array
{
    putenv($dashboardUrl === null ? 'MEMRY_DASHBOARD_URL' : "MEMRY_DASHBOARD_URL={$dashboardUrl}");

    try {
        return require config_path('cors.php');
    } finally {
        putenv('MEMRY_DASHBOARD_URL');
    }
}

function preflight(string $origin, string $path = '/api/session')
{
    return test()->call('OPTIONS', $path, [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ]);
}

test('a preflight from the dashboard origin is allowed with credentials', function () {
    config(['cors' => corsConfigFor(CORS_DASHBOARD)]);

    $response = preflight(CORS_DASHBOARD);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe(CORS_DASHBOARD)
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});

test('a preflight from any other origin gets no allow-origin header', function () {
    config(['cors' => corsConfigFor(CORS_DASHBOARD)]);

    expect(preflight('https://evil.example')->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and(preflight('https://app.memry.test.evil.example')->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

test('the sanctum csrf cookie route is covered by CORS too', function () {
    config(['cors' => corsConfigFor(CORS_DASHBOARD)]);

    expect(preflight(CORS_DASHBOARD, '/sanctum/csrf-cookie')->headers->get('Access-Control-Allow-Origin'))->toBe(CORS_DASHBOARD);
});

test('by default no origin is allowed and credentials never pair with a wildcard', function () {
    $config = corsConfigFor(null);

    expect($config['allowed_origins'])->toBe([])
        ->and($config['allowed_origins_patterns'])->toBe([])
        ->and($config['supports_credentials'])->toBeTrue();

    config(['cors' => $config]);
    expect(preflight(CORS_DASHBOARD)->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

test('MEMRY_DASHBOARD_URL accepts a comma-separated list and ignores blanks', function () {
    config(['cors' => corsConfigFor(' https://app.memry.test , ,http://localhost:5173/ ')]);

    expect(preflight('https://app.memry.test')->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.memry.test')
        ->and(preflight('http://localhost:5173')->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173')
        ->and(preflight('https://other.example')->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

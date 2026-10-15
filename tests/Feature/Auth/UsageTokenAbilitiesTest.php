<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function usageTokenFor(User $user): string
{
    $teamId = contextFor($user)->teamId;

    return $user->createTeamToken('memry-usage', $teamId, ['usage:write'])->plainTextToken;
}

test('it rejects a usage-only token on the MCP endpoint', function () {
    $token = usageTokenFor(User::factory()->create());

    $this->withToken($token)
        ->postJson('/mcp/memory', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertForbidden();
});

test('it rejects a usage-only token on representative API routes', function (string $method, string $uri) {
    $token = usageTokenFor(User::factory()->create());

    $this->withToken($token)->json($method, $uri)->assertForbidden();
})->with([
    ['GET', '/api/me'],
    ['GET', '/api/memories'],
    ['GET', '/api/stats'],
    ['GET', '/api/context'],
    ['GET', '/api/auth/tokens'],
    ['DELETE', '/api/auth/token'],
    ['DELETE', '/api/auth/tokens/1'],
    ['DELETE', '/api/account'],
    ['DELETE', '/api/session'],
]);

test('every sanctum route except the OTLP ingest rejects a usage-only token', function () {
    $token = usageTokenFor(User::factory()->create());
    $exempt = ['api/otlp/v1/metrics'];

    $guarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => in_array($route->uri(), $exempt, true));

    expect($guarded)->not->toBeEmpty();

    foreach ($guarded as $route) {
        $uri = '/'.preg_replace('/\{[^}]+\??\}/', '1', $route->uri());
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            app('auth')->forgetGuards();
            $this->withToken($token)->json($method, $uri)
                ->assertForbidden("{$method} {$uri} accepted a usage-only token");
        }
    }
});

test('full-access tokens and dashboard sessions still pass the ability check', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId)->plainTextToken;

    $this->withToken($full)->getJson('/api/me')->assertOk();
    $this->withToken($full)
        ->postJson('/mcp/memory', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk();

    app('auth')->forgetGuards();
    $this->withoutToken()->actingAs($user, 'web')->getJson('/api/me')->assertOk();
});

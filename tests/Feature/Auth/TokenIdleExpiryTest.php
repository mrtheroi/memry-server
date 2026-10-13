<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mcpPing(string $token)
{
    return test()->withToken($token)->postJson('/mcp/memory', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
}

test('a token idle for 29 days still works', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    $this->travel(29)->days();

    $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertOk();
});

test('a token idle for 31 days is rejected on the API like an invalid token', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    $this->travel(31)->days();

    $this->withToken($token)->getJson('/api/context?project=dbmcp')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a token idle for 31 days is rejected on the MCP endpoint', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    $this->travel(31)->days();

    mcpPing($token)->assertUnauthorized();
});

test('a token created 60 days ago but used yesterday still works', function () {
    $user = User::factory()->create();
    $token = $user->createToken('memry-cli');
    $user->tokens()->whereKey($token->accessToken->id)->update(['created_at' => now()->subDays(60), 'last_used_at' => now()->subDay()]);

    $this->withToken($token->plainTextToken)->getJson('/api/context?project=dbmcp')->assertOk();
});

test('using a token refreshes its idle window', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    foreach ([20, 20, 20] as $days) {
        $this->travel($days)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertOk();
    }
});

test('the idle window is configurable', function () {
    config(['memry.tokens.idle_days' => 7]);
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    $this->travel(8)->days();

    $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertUnauthorized();
});

test('the absolute lifetime composes with the idle window', function () {
    config(['sanctum.expiration' => 60 * 24 * 10]);
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    foreach ([4, 5] as $days) {
        $this->travel($days)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertOk();
    }

    // used 2 days ago (idle ok) but older than the 10 day absolute lifetime
    $this->travel(2)->days();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertUnauthorized();
});

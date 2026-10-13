<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it lists only the caller tokens, marks the current one and exposes no secrets', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $current = $user->createToken('memry-cli');
    $other = $user->createToken('claude-code');
    User::factory()->create()->createToken('foreign');
    $other->accessToken->forceFill(['last_used_at' => now()->subDays(2)])->save();

    $response = $this->withToken($current->plainTextToken)->getJson('/api/auth/tokens')->assertOk();

    $rows = collect($response->json('data'))->keyBy('id');
    expect($rows->keys()->sort()->values()->all())->toBe(collect([$current->accessToken->id, $other->accessToken->id])->sort()->values()->all())
        ->and($rows[$current->accessToken->id]['current'])->toBeTrue()
        ->and($rows[$other->accessToken->id])->toMatchArray(['name' => 'claude-code', 'current' => false])
        ->and($rows[$other->accessToken->id]['last_used_at'])->toBe(now()->subDays(2)->toJSON())
        ->and($rows[$other->accessToken->id]['expires_at'])->toBe(now()->subDays(2)->addDays(30)->toJSON())
        ->and(array_keys($rows[$current->accessToken->id]))->toBe(['id', 'name', 'created_at', 'last_used_at', 'expires_at', 'current']);
    expect($response->getContent())->not->toContain($current->plainTextToken)->not->toContain('"token"');
});

test('it requires a valid token to list tokens', function () {
    $this->getJson('/api/auth/tokens')->assertUnauthorized();
});

test('it revokes one token of the caller and keeps the others', function () {
    $user = User::factory()->create();
    $caller = $user->createToken('memry-cli')->plainTextToken;
    $target = $user->createToken('claude-code');
    $keep = $user->createToken('keep')->plainTextToken;

    $this->withToken($caller)->deleteJson('/api/auth/tokens/'.$target->accessToken->id)
        ->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($target->plainTextToken)->getJson('/api/context?project=dbmcp')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($keep)->getJson('/api/context?project=dbmcp')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($caller)->getJson('/api/context?project=dbmcp')->assertOk();
});

test('it answers 404 for a foreign or unknown token id, indistinguishably', function () {
    $caller = User::factory()->create()->createToken('memry-cli')->plainTextToken;
    $foreign = User::factory()->create()->createToken('foreign');

    $a = $this->withToken($caller)->deleteJson('/api/auth/tokens/'.$foreign->accessToken->id);
    $b = $this->withToken($caller)->deleteJson('/api/auth/tokens/999999');

    $a->assertNotFound();
    $b->assertNotFound();
    expect($a->getContent())->toBe($b->getContent())
        ->and($foreign->accessToken->fresh())->not->toBeNull();
});

test('listing tokens is throttled per user', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    foreach (range(1, 10) as $i) {
        $this->withToken($token)->getJson('/api/auth/tokens')->assertOk();
    }
    $this->withToken($token)->getJson('/api/auth/tokens')->assertTooManyRequests();
});

test('revoking one token is throttled per user', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    foreach (range(1, 10) as $i) {
        $this->withToken($token)->deleteJson('/api/auth/tokens/999999')->assertNotFound();
    }
    $this->withToken($token)->deleteJson('/api/auth/tokens/999999')->assertTooManyRequests();
});

test('revoking the current token is throttled per user', function () {
    $user = User::factory()->create();
    $tokens = collect(range(1, 11))->map(fn ($i) => $user->createToken("t{$i}")->plainTextToken);

    foreach ($tokens->take(10) as $token) {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->deleteJson('/api/auth/token')->assertNoContent();
    }
    $this->app['auth']->forgetGuards();
    $this->withToken($tokens->last())->deleteJson('/api/auth/token')->assertTooManyRequests();
});

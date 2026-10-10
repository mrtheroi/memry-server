<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it revokes the token used for the request and answers 204', function () {
    $user = User::factory()->create();
    $token = $user->createToken('memry-cli');

    $this->withToken($token->plainTextToken)->deleteJson('/api/auth/token')
        ->assertNoContent()
        ->assertContent('');

    expect($user->tokens()->whereKey($token->accessToken->id)->exists())->toBeFalse();
});

test('it keeps the other tokens of the user valid', function () {
    $user = User::factory()->create();
    $revoked = $user->createToken('memry-cli')->plainTextToken;
    $other = $user->createToken('claude-code')->plainTextToken;

    $this->withToken($revoked)->deleteJson('/api/auth/token')->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($other)->get('/api/context?project=dbmcp')->assertOk();
});

test('it rejects revocation without a valid token', function () {
    $this->deleteJson('/api/auth/token')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $this->withToken('1|not-a-real-token')->deleteJson('/api/auth/token')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a revoked token no longer authenticates', function () {
    $token = User::factory()->create()->createToken('memry-cli')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/auth/token')->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->deleteJson('/api/auth/token')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withToken($token)->getJson('/api/context?project=dbmcp')->assertUnauthorized();
});

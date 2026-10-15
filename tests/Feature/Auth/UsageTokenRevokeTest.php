<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function usageTokenIdFor(User $user): int
{
    return $user->createTeamToken('memry-usage', contextFor($user)->teamId, ['usage:write'])->accessToken->getKey();
}

test('without keep it revokes every usage token of the caller and keeps full-access tokens', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    usageTokenIdFor($user);
    usageTokenIdFor($user);

    $this->withToken($full->plainTextToken)->deleteJson('/api/auth/usage-tokens')->assertNoContent();

    expect($user->tokens()->pluck('id')->all())->toBe([$full->accessToken->getKey()]);
});

test('with keep it revokes the other usage tokens and keeps that one', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    usageTokenIdFor($user);
    $kept = $user->createTeamToken('memry-usage', contextFor($user)->teamId, ['usage:write']);

    $this->withToken($full->plainTextToken)
        ->deleteJson('/api/auth/usage-tokens?keep='.$kept->accessToken->getKey())
        ->assertNoContent();

    expect($user->tokens()->orderBy('id')->pluck('id')->all())
        ->toBe([$full->accessToken->getKey(), $kept->accessToken->getKey()]);

    app('auth')->forgetGuards();
    $this->withToken($kept->plainTextToken)->postJson('/api/otlp/v1/metrics', ['resourceMetrics' => []])->assertSuccessful();
});

test('keep naming another user\'s usage token answers 422 and revokes nothing', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    usageTokenIdFor($user);
    $foreign = usageTokenIdFor(User::factory()->create());

    $this->withToken($full->plainTextToken)
        ->deleteJson('/api/auth/usage-tokens?keep='.$foreign)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('keep');

    expect($user->tokens()->count())->toBe(2)
        ->and(User::query()->whereKeyNot($user->id)->sole()->tokens()->count())->toBe(1);
});

test('keep naming the caller\'s full-access token answers 422 and revokes nothing', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    usageTokenIdFor($user);

    $this->withToken($full->plainTextToken)
        ->deleteJson('/api/auth/usage-tokens?keep='.$full->accessToken->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('keep');

    expect($user->tokens()->count())->toBe(2);
});

test('keep that is not an integer answers 422 and revokes nothing', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    usageTokenIdFor($user);

    $this->withToken($full->plainTextToken)
        ->deleteJson('/api/auth/usage-tokens?keep=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('keep');

    expect($user->tokens()->count())->toBe(2);
});

test('a full-access token is never revoked, even one named memry-usage', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    $fullNamedUsage = $user->createTeamToken('memry-usage', contextFor($user)->teamId);
    usageTokenIdFor($user);

    $this->withToken($full->plainTextToken)->deleteJson('/api/auth/usage-tokens')->assertNoContent();

    expect($user->tokens()->orderBy('id')->pluck('id')->all())
        ->toBe([$full->accessToken->getKey(), $fullNamedUsage->accessToken->getKey()]);
});

test('another user\'s tokens are never revoked', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    $otherFull = $other->createTeamToken('memry-cli', contextFor($other)->teamId);
    $otherUsage = usageTokenIdFor($other);

    $this->withToken($full->plainTextToken)->deleteJson('/api/auth/usage-tokens')->assertNoContent();

    expect($other->tokens()->orderBy('id')->pluck('id')->all())
        ->toBe([$otherFull->accessToken->getKey(), $otherUsage]);
});

test('a usage token cannot revoke usage tokens', function () {
    $user = User::factory()->create();
    $usage = $user->createTeamToken('memry-usage', contextFor($user)->teamId, ['usage:write']);

    $this->withToken($usage->plainTextToken)->deleteJson('/api/auth/usage-tokens')->assertForbidden();

    expect($user->tokens()->count())->toBe(1);
});

test('it requires authentication to revoke usage tokens', function () {
    $this->deleteJson('/api/auth/usage-tokens')->assertUnauthorized();
});

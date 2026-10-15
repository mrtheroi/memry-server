<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function usageTokenIdFor(User $user): int
{
    return $user->createTeamToken('memry-usage', contextFor($user)->teamId, ['usage:write'])->accessToken->getKey();
}

test('it revokes every usage token of the caller and keeps full-access tokens', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    usageTokenIdFor($user);
    usageTokenIdFor($user);

    $this->withToken($full->plainTextToken)->deleteJson('/api/auth/usage-tokens')->assertNoContent();

    expect($user->tokens()->pluck('id')->all())->toBe([$full->accessToken->getKey()]);
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

test('a keep parameter is not part of the contract: every usage token is still revoked', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId);
    $newest = usageTokenIdFor($user);

    $this->withToken($full->plainTextToken)
        ->deleteJson('/api/auth/usage-tokens?keep='.$newest)
        ->assertNoContent();

    expect($user->tokens()->pluck('id')->all())->toBe([$full->accessToken->getKey()]);
});

test('overlapping clients revoking their exact previous ids leave both new usage tokens alive', function () {
    $user = User::factory()->create();
    $full = $user->createTeamToken('memry-cli', contextFor($user)->teamId)->plainTextToken;
    $previousA = usageTokenIdFor($user);
    $previousB = usageTokenIdFor($user);

    $newA = $this->withToken($full)->postJson('/api/auth/usage-token')->assertCreated()->json('id');
    app('auth')->forgetGuards();
    $newB = $this->withToken($full)->postJson('/api/auth/usage-token')->assertCreated()->json('id');

    app('auth')->forgetGuards();
    $this->withToken($full)->deleteJson('/api/auth/tokens/'.$previousA)->assertNoContent();
    app('auth')->forgetGuards();
    $this->withToken($full)->deleteJson('/api/auth/tokens/'.$previousB)->assertNoContent();

    expect($user->tokens()->where('name', 'memry-usage')->orderBy('id')->pluck('id')->all())->toBe([$newA, $newB]);
});

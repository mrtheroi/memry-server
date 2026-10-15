<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function fullTokenFor(User $user): string
{
    return $user->createTeamToken('memry-cli', contextFor($user)->teamId)->plainTextToken;
}

test('it issues a team-bound usage token limited to usage:write', function () {
    $user = User::factory()->create();

    $response = $this->withToken(fullTokenFor($user))->postJson('/api/auth/usage-token')
        ->assertCreated()
        ->assertJsonStructure(['token']);

    [$id] = explode('|', $response->json('token'), 2);
    $row = $user->tokens()->findOrFail($id);

    expect($row->name)->toBe('memry-usage')
        ->and($row->abilities)->toBe(['usage:write'])
        ->and($row->team_id)->toBe(contextFor($user)->teamId);

    app('auth')->forgetGuards();
    $this->withToken($response->json('token'))->getJson('/api/me')->assertForbidden();
});

test('it rotates: the previous usage token is deleted and other tokens are kept', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $full = fullTokenFor($user);
    $otherUsage = $other->createTeamToken('memry-usage', contextFor($other)->teamId, ['usage:write']);

    $first = $this->withToken($full)->postJson('/api/auth/usage-token')->json('token');
    app('auth')->forgetGuards();
    $second = $this->withToken($full)->postJson('/api/auth/usage-token')->json('token');

    $usage = $user->tokens()->where('name', 'memry-usage')->get();
    expect($usage)->toHaveCount(1)
        ->and($usage->sole()->getKey())->toBe((int) explode('|', $second)[0])
        ->and($user->tokens()->count())->toBe(2)
        ->and($first)->not->toBe($second)
        ->and($other->tokens()->whereKey($otherUsage->accessToken->id)->exists())->toBeTrue();
});

test('a usage token cannot mint another usage token', function () {
    $user = User::factory()->create();
    $usage = $this->withToken(fullTokenFor($user))->postJson('/api/auth/usage-token')->json('token');

    app('auth')->forgetGuards();
    $this->withToken($usage)->postJson('/api/auth/usage-token')->assertForbidden();

    expect($user->tokens()->where('name', 'memry-usage')->count())->toBe(1);
});

test('it requires authentication to issue a usage token', function () {
    $this->postJson('/api/auth/usage-token')->assertUnauthorized();
});

test('the tokens list shows the abilities of each token', function () {
    $user = User::factory()->create();
    $full = fullTokenFor($user);
    $this->withToken($full)->postJson('/api/auth/usage-token')->assertCreated();

    app('auth')->forgetGuards();
    $rows = collect($this->withToken($full)->getJson('/api/auth/tokens')->assertOk()->json('data'))->keyBy('name');

    expect($rows['memry-cli']['abilities'])->toBe(['*'])
        ->and($rows['memry-usage']['abilities'])->toBe(['usage:write']);
});

test('a usage token expires after the idle window like any other token', function () {
    $user = User::factory()->create();
    $usage = $this->withToken(fullTokenFor($user))->postJson('/api/auth/usage-token')->json('token');

    $this->travel(config('memry.tokens.idle_days') + 1)->days();
    app('auth')->forgetGuards();

    $this->withToken($usage)->getJson('/api/me')->assertUnauthorized();
});

test('it answers 403 and creates nothing when the token team has no membership for the user', function () {
    $user = User::factory()->create();
    $strangerTeam = contextFor(User::factory()->create())->teamId;
    $stale = $user->createTeamToken('memry-cli', $strangerTeam)->plainTextToken;

    $this->withToken($stale)->postJson('/api/auth/usage-token')
        ->assertForbidden()
        ->assertJsonStructure(['message']);

    expect($user->tokens()->where('name', 'memry-usage')->exists())->toBeFalse();
});

test('it locks the user row before deleting and inserting so rotations serialize', function () {
    $user = User::factory()->create();
    $full = fullTokenFor($user);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $this->withToken($full)->postJson('/api/auth/usage-token')->assertCreated();

    $position = fn (callable $match) => collect($queries)->search($match);
    $lock = $position(fn ($sql) => str_contains($sql, 'from "users"') && str_contains($sql, 'for update'));
    $delete = $position(fn ($sql) => str_starts_with($sql, 'delete from "personal_access_tokens"'));
    $insert = $position(fn ($sql) => str_starts_with($sql, 'insert into "personal_access_tokens"'));

    expect($lock)->not->toBeFalse()
        ->and($lock)->toBeLessThan($delete)
        ->and($delete)->toBeLessThan($insert);
});

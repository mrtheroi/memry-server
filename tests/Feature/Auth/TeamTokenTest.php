<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function tokenPersonalTeamId(User $user): int
{
    return TeamRecord::where('owner_id', $user->id)->where('personal_team', true)->value('id');
}

it('uses the app token model', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(PersonalAccessToken::class);
});

it('creates a team token in one insert with the standard token shape', function () {
    $user = User::factory()->create();
    $teamId = tokenPersonalTeamId($user);

    DB::enableQueryLog();
    $new = $user->createTeamToken('memry-cli', $teamId);
    $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'insert'));
    DB::disableQueryLog();

    [$id, $plain] = explode('|', $new->plainTextToken, 2);
    $row = DB::table('personal_access_tokens')->where('id', $id)->sole();

    expect($inserts)->toHaveCount(1)
        ->and($new->accessToken)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($plain)->toHaveLength(48)
        ->and(substr($plain, 40))->toBe(hash('crc32b', substr($plain, 0, 40)))
        ->and($row->team_id)->toBe($teamId)
        ->and($row->name)->toBe('memry-cli')
        ->and($row->abilities)->toBe('["*"]')
        ->and($row->token)->toBe(hash('sha256', $plain))
        ->and($row->tokenable_id)->toBe($user->id)
        ->and($user->tokens()->count())->toBe(1);
});

it('authenticates a team token through sanctum', function () {
    $user = User::factory()->create();
    $plain = $user->createTeamToken('mcp', tokenPersonalTeamId($user))->plainTextToken;

    $found = (Sanctum::$personalAccessTokenModel)::findToken($plain);

    expect($found)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($found->team_id)->toBe(tokenPersonalTeamId($user));
});

it('serves requests with a team token and with a legacy token alike', function () {
    $user = User::factory()->create();
    $team = $user->createTeamToken('mcp', tokenPersonalTeamId($user))->plainTextToken;
    $legacy = $user->createToken('legacy')->plainTextToken;

    $this->withToken($team)->get('/api/context?project=dbmcp')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($legacy)->get('/api/context?project=dbmcp')->assertOk();
});

it('revokes a team token like any other', function () {
    $user = User::factory()->create();
    $new = $user->createTeamToken('memry-cli', tokenPersonalTeamId($user));

    $this->withToken($new->plainTextToken)->deleteJson('/api/auth/token')->assertNoContent()->assertContent('');

    expect($user->tokens()->count())->toBe(0);
});

it('deletes every token of the user with tokens()->delete()', function () {
    $user = User::factory()->create();
    $user->createTeamToken('a', tokenPersonalTeamId($user));
    $user->createToken('b');

    $user->tokens()->delete();

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

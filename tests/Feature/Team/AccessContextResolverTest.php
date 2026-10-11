<?php

use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\Role;
use App\Team\Infrastructure\EloquentAccessContextResolver;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use App\Team\Infrastructure\RoleProjectAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function resolveCtxFor(?User $user): AccessContext
{
    return (new EloquentAccessContextResolver(new RoleProjectAccessPolicy))->resolve($user);
}

function ctxPersonalTeam(User $user): TeamRecord
{
    return TeamRecord::where('owner_id', $user->id)->where('personal_team', true)->sole();
}

it('resolves a team-bound token to that team, as owner, with every project', function () {
    $user = User::factory()->create();
    $shared = TeamRecord::factory()->create(['owner_id' => $user->id]);
    $token = $user->createTeamToken('mcp', $shared->id)->accessToken;
    $user->withAccessToken($token);

    $ctx = resolveCtxFor($user);

    expect($ctx->teamId)->toBe($shared->id)
        ->and($ctx->userId)->toBe($user->id)
        ->and($ctx->role)->toBe(Role::Owner)
        ->and($ctx->projects->isAll())->toBeTrue();
});

it('falls back to the personal team for a legacy token without a team', function () {
    $user = User::factory()->create();
    $user->withAccessToken($user->createToken('legacy')->accessToken);

    $ctx = resolveCtxFor($user);

    expect($ctx->teamId)->toBe(ctxPersonalTeam($user)->id)->and($ctx->role)->toBe(Role::Owner);
});

it('falls back to the personal team for a transient token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    expect(resolveCtxFor($user)->teamId)->toBe(ctxPersonalTeam($user)->id);
});

it('resolves a member to the member role with no project access', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = ctxPersonalTeam($owner);
    TeamUserRecord::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member]);
    $member->withAccessToken($member->createTeamToken('mcp', $team->id)->accessToken);

    $ctx = resolveCtxFor($member);

    expect($ctx->teamId)->toBe($team->id)
        ->and($ctx->role)->toBe(Role::Member)
        ->and($ctx->projects->isAll())->toBeFalse()
        ->and($ctx->projects->ids())->toBe([]);
});

it('is denied without a user', function () {
    expect(resolveCtxFor(null)->isDenied())->toBeTrue()->and(resolveCtxFor(null)->userId)->toBeNull();
});

it('is denied when the token team has no membership for the user', function () {
    $user = User::factory()->create();
    $strangerTeam = ctxPersonalTeam(User::factory()->create());
    $user->withAccessToken($user->createTeamToken('mcp', $strangerTeam->id)->accessToken);

    $ctx = resolveCtxFor($user);

    expect($ctx->isDenied())->toBeTrue()->and($ctx->userId)->toBe($user->id);
});

it('is denied when the user has no personal team', function () {
    $user = User::factory()->withoutPersonalTeam()->create();
    $user->withAccessToken($user->createToken('legacy')->accessToken);

    expect(resolveCtxFor($user)->isDenied())->toBeTrue();
});

it('is denied when the personal team has no owner membership row', function () {
    $user = User::factory()->create();
    TeamUserRecord::where('user_id', $user->id)->delete();

    expect(resolveCtxFor($user)->isDenied())->toBeTrue();
});

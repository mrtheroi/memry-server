<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// The ownership constraints are DEFERRABLE INITIALLY DEFERRED, so they are only
// checked at COMMIT. RefreshDatabase never commits: force the check instead.
function checkDeferredConstraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function insertTeam(int $ownerId): int
{
    return DB::table('teams')->insertGetId([
        'owner_id' => $ownerId, 'slug' => bin2hex(random_bytes(8)),
    ]);
}

function insertMembership(int $teamId, int $userId, string $role): void
{
    DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $userId, 'role' => $role]);
}

test('an owner membership for someone other than the team owner is rejected', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $other->id, 'owner');

    expect(fn () => checkDeferredConstraints())->toThrow(QueryException::class, 'violates foreign key constraint');
});

test('a team without an owner membership is rejected once constraints are checked', function () {
    insertTeam(User::factory()->create()->id);

    expect(fn () => checkDeferredConstraints())->toThrow(QueryException::class, 'teams_owner_membership_foreign');
});

test('a team and its matching owner membership inserted together are accepted', function () {
    $owner = User::factory()->create();
    insertMembership(insertTeam($owner->id), $owner->id, 'owner');

    checkDeferredConstraints();

    expect(DB::table('team_user')->count())->toBe(1);
});

test('ownership can be transferred inside one transaction', function () {
    [$owner, $heir] = User::factory()->count(2)->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $owner->id, 'owner');
    insertMembership($team, $heir->id, 'admin');
    checkDeferredConstraints();

    DB::table('team_user')->where(['team_id' => $team, 'user_id' => $owner->id])->update(['role' => 'admin']);
    DB::table('teams')->where('id', $team)->update(['owner_id' => $heir->id]);
    DB::table('team_user')->where(['team_id' => $team, 'user_id' => $heir->id])->update(['role' => 'owner']);

    checkDeferredConstraints();

    expect(DB::table('team_user')->where(['team_id' => $team, 'role' => 'owner'])->value('user_id'))->toBe($heir->id);
});

test('deleting the owner user cascades the team and every membership', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $owner->id, 'owner');
    insertMembership($team, $member->id, 'member');

    $owner->delete();
    checkDeferredConstraints();

    expect(DB::table('teams')->where('id', $team)->exists())->toBeFalse()
        ->and(DB::table('team_user')->where('team_id', $team)->exists())->toBeFalse();
});

test('deleting only the owner membership while the team exists is rejected', function () {
    $owner = User::factory()->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $owner->id, 'owner');
    checkDeferredConstraints();

    DB::table('team_user')->where(['team_id' => $team, 'user_id' => $owner->id])->delete();

    expect(fn () => checkDeferredConstraints())->toThrow(QueryException::class, 'teams_owner_membership_foreign');
});

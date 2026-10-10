<?php

use App\Models\User;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// team_user_owner_foreign is DEFERRABLE INITIALLY IMMEDIATE: checked per
// statement in normal use. Only an ownership transfer defers it.

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

test('an owner membership for someone other than the team owner is rejected immediately', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    $team = insertTeam($owner->id);

    expect(fn () => insertMembership($team, $other->id, 'owner'))
        ->toThrow(QueryException::class, 'team_user_owner_foreign');
});

// Fail-closed rationale: the database no longer requires an owner row. A team
// without one grants nobody anything, because AccessContext denies when the
// membership is missing. Provisioning creates team and owner row atomically
// (step 4), and a backfill --check reports teams without an owner row.
test('a team without an owner membership is accepted', function () {
    $team = insertTeam(User::factory()->create()->id);

    expect(DB::table('team_user')->where('team_id', $team)->exists())->toBeFalse();
});

test('a team and its matching owner membership are accepted', function () {
    $owner = User::factory()->create();
    insertMembership(insertTeam($owner->id), $owner->id, 'owner');

    expect(DB::table('team_user')->count())->toBe(1);
});

function seedTransferableTeam(): array
{
    [$owner, $heir] = User::factory()->count(2)->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $owner->id, 'owner');
    insertMembership($team, $heir->id, 'admin');

    return [$team, $owner, $heir];
}

function transferOwnership(int $team, User $from, User $to): void
{
    DB::table('teams')->where('id', $team)->update(['owner_id' => $to->id]);
    DB::table('team_user')->where(['team_id' => $team, 'user_id' => $from->id])->update(['role' => 'admin']);
    DB::table('team_user')->where(['team_id' => $team, 'user_id' => $to->id])->update(['role' => 'owner']);
}

test('ownership can be transferred in one transaction that defers the owner constraint', function () {
    [$team, $owner, $heir] = seedTransferableTeam();

    DB::statement('SET CONSTRAINTS team_user_owner_foreign DEFERRED');
    transferOwnership($team, $owner, $heir);
    DB::statement('SET CONSTRAINTS team_user_owner_foreign IMMEDIATE');

    expect(DB::table('team_user')->where(['team_id' => $team, 'role' => 'owner'])->value('user_id'))->toBe($heir->id);
});

test('the same transfer without deferring fails at the first statement', function () {
    [$team, $owner, $heir] = seedTransferableTeam();

    expect(fn () => transferOwnership($team, $owner, $heir))
        ->toThrow(QueryException::class, 'team_user_owner_foreign');
});

test('updating teams.owner_id alone is rejected', function () {
    [$team, , $heir] = seedTransferableTeam();

    expect(fn () => DB::table('teams')->where('id', $team)->update(['owner_id' => $heir->id]))
        ->toThrow(QueryException::class, 'team_user_owner_foreign');
});

test('deleting the owner user cascades the team and every membership', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $owner->id, 'owner');
    insertMembership($team, $member->id, 'member');

    $owner->delete();

    expect(DB::table('teams')->where('id', $team)->exists())->toBeFalse()
        ->and(DB::table('team_user')->where('team_id', $team)->exists())->toBeFalse();
});

test('deleting only the owner membership is allowed', function () {
    $owner = User::factory()->create();
    $team = insertTeam($owner->id);
    insertMembership($team, $owner->id, 'owner');

    DB::table('team_user')->where(['team_id' => $team, 'user_id' => $owner->id])->delete();

    expect(DB::table('teams')->where('id', $team)->exists())->toBeTrue()
        ->and(DB::table('team_user')->where('team_id', $team)->exists())->toBeFalse();
});

test('bulk inserting teams through the factory violates nothing', function () {
    TeamRecord::factory()->count(3)->make()->each(
        fn (TeamRecord $team) => TeamRecord::factory()->insert($team->getAttributes())
    );

    expect(DB::table('teams')->count())->toBe(3);
});

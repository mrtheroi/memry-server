<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the same user cannot join a team twice', function () {
    $team = TeamRecord::factory()->create();
    $member = User::factory()->create();
    TeamUserRecord::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member]);

    expect(fn () => TeamUserRecord::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Admin]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the database rejects a role outside owner, admin and member', function () {
    $team = TeamRecord::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('team_user')->insert([
        'team_id' => $team->id, 'user_id' => $user->id, 'role' => 'superuser',
    ]))->toThrow(QueryException::class, 'team_user_role_check');
});

test('a team has exactly one owner but many admins and members', function () {
    $team = TeamRecord::factory()->create();
    TeamUserRecord::factory()->count(2)->create(['team_id' => $team->id, 'role' => Role::Admin]);
    TeamUserRecord::factory()->count(2)->create(['team_id' => $team->id, 'role' => Role::Member]);

    expect(fn () => TeamUserRecord::factory()->create(['team_id' => $team->id, 'role' => Role::Owner]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the role is cast to the Role enum', function () {
    $membership = TeamUserRecord::factory()->create(['role' => Role::Admin])->refresh();

    expect($membership->role)->toBe(Role::Admin);
});

test('deleting a user removes their memberships', function () {
    $membership = TeamUserRecord::factory()->create();

    User::findOrFail($membership->user_id)->delete();

    expect(TeamUserRecord::find($membership->id))->toBeNull();
});

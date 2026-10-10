<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a team defaults to the free plan with one seat', function () {
    $team = TeamRecord::factory()->create()->refresh();

    expect($team->plan)->toBe('free')
        ->and($team->seats)->toBe(1)
        ->and($team->personal_team)->toBeFalse();
});

test('a team slug is unique', function () {
    TeamRecord::factory()->create(['slug' => 'acme']);

    expect(fn () => TeamRecord::factory()->create(['slug' => 'acme']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an owner can have only one personal team but many shared teams', function () {
    $owner = User::factory()->create();
    TeamRecord::factory()->create(['owner_id' => $owner->id, 'personal_team' => true]);
    TeamRecord::factory()->count(2)->create(['owner_id' => $owner->id]);

    expect(TeamRecord::where('owner_id', $owner->id)->count())->toBe(3);
    expect(fn () => TeamRecord::factory()->create(['owner_id' => $owner->id, 'personal_team' => true]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('deleting a user deletes the teams they own', function () {
    $team = TeamRecord::factory()->create();

    User::findOrFail($team->owner_id)->delete();

    expect(TeamRecord::find($team->id))->toBeNull();
});

test('the database rejects fewer than one seat', function (int $seats) {
    expect(fn () => TeamRecord::factory()->create(['seats' => $seats]))
        ->toThrow(QueryException::class, 'teams_seats_check');
})->with([0, -1]);

test('a team created through its factory also has its owner membership', function () {
    $team = TeamRecord::factory()->create();

    $owners = TeamUserRecord::where('team_id', $team->id)->where('role', Role::Owner)->get();

    expect($owners)->toHaveCount(1)
        ->and($owners->first()->user_id)->toBe($team->owner_id);
});

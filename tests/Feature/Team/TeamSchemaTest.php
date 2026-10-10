<?php

use App\Models\User;
use App\Team\Infrastructure\Persistence\TeamRecord;
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

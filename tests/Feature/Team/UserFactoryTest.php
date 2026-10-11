<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates users with their personal team and owner membership', function () {
    $user = User::factory()->create();

    $team = TeamRecord::where('owner_id', $user->id)->where('personal_team', true)->sole();

    expect(TeamUserRecord::where('team_id', $team->id)->where('user_id', $user->id)->sole()->role)->toBe(Role::Owner);
});

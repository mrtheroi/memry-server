<?php

use App\Memory\Domain\Observation;
use App\Memory\Domain\UserPrompt;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function personalTeamIdOf(User $user): ?int
{
    return DB::table('teams')->where('owner_id', $user->id)->where('personal_team', true)->value('id');
}

test('an observation saved without a team lands in the personal team of its author', function () {
    $user = User::factory()->create();

    $saved = (new EloquentMemoryRepository)->save(new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null));

    expect($saved->teamId)->toBe(personalTeamIdOf($user))
        ->and(DB::table('observations')->value('team_id'))->toBe(personalTeamIdOf($user));
});

test('a prompt saved without a team lands in the personal team of its author', function () {
    $user = User::factory()->create();

    (new EloquentPromptRepository)->save(new UserPrompt($user->id, 's', 'dbmcp', 'hello'));

    expect(DB::table('user_prompts')->value('team_id'))->toBe(personalTeamIdOf($user));
});

test('a user without a team gets a personal team on their first write', function () {
    $user = User::factory()->withoutPersonalTeam()->create();

    (new EloquentMemoryRepository)->save(new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null));
    (new EloquentPromptRepository)->save(new UserPrompt($user->id, 's', 'dbmcp', 'hello'));

    expect(personalTeamIdOf($user))->not->toBeNull()
        ->and(DB::table('observations')->value('team_id'))->toBe(personalTeamIdOf($user))
        ->and(DB::table('user_prompts')->value('team_id'))->toBe(personalTeamIdOf($user))
        ->and(DB::table('team_user')->where('user_id', $user->id)->value('role'))->toBe('owner');
});

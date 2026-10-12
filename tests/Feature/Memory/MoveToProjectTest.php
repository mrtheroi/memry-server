<?php

use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('moving a project issues exactly one statement so project creation and the move share a snapshot', function () {
    $user = User::factory()->create();
    $teamId = TeamRecord::where('owner_id', $user->id)->value('id');
    $legacy = User::factory()->withoutPersonalTeam()->create();
    $repository = new EloquentMemoryRepository;
    $repository->save(new Observation($user->id, 's', 'decision', 'T', 'C', 'old', 'project', null, teamId: $teamId));
    $repository->save(new Observation($legacy->id, 's', 'decision', 'T', 'C', 'old', 'project', null));

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });
    $moved = $repository->moveToProject(AccessContext::system(), 'old', 'new');

    expect($moved)->toBe(2)
        ->and($statements)->toHaveCount(1)
        ->and(DB::table('observations')->where('user_id', $user->id)->value('project_id'))
        ->toBe(DB::table('projects')->where('team_id', $teamId)->where('name', 'new')->value('id'))
        ->and(DB::table('observations')->where('user_id', $legacy->id)->value('project_id'))->toBeNull();
});

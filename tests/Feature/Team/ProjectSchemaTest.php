<?php

use App\Models\User;
use App\Team\Infrastructure\Persistence\ProjectGrantRecord;
use App\Team\Infrastructure\Persistence\ProjectRecord;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a project name is unique within a team but reusable across teams', function () {
    $team = TeamRecord::factory()->create();
    ProjectRecord::factory()->create(['team_id' => $team->id, 'name' => 'dbmcp']);
    ProjectRecord::factory()->create(['name' => 'dbmcp']);

    expect(fn () => ProjectRecord::factory()->create(['team_id' => $team->id, 'name' => 'dbmcp']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a user is granted a project only once', function () {
    $project = ProjectRecord::factory()->create();
    $user = User::factory()->create();
    ProjectGrantRecord::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);

    expect(fn () => ProjectGrantRecord::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('deleting a team deletes its projects and their grants', function () {
    $grant = ProjectGrantRecord::factory()->create();
    $project = ProjectRecord::findOrFail($grant->project_id);

    TeamRecord::findOrFail($project->team_id)->delete();

    expect(ProjectRecord::find($project->id))->toBeNull()
        ->and(ProjectGrantRecord::find($grant->id))->toBeNull();
});

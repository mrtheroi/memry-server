<?php

use App\Memory\Domain\ProjectName;
use App\Models\User;
use App\Team\Infrastructure\Persistence\ProjectGrantRecord;
use App\Team\Infrastructure\Persistence\ProjectRecord;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

test('a user_id index exists on project_grants', function () {
    $indexes = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'project_grants'"))
        ->pluck('indexdef');

    expect($indexes->contains(fn ($def) => str_contains($def, '(user_id)')))->toBeTrue();
});

test('the database accepts a canonical project name', function () {
    expect(ProjectRecord::factory()->create(['name' => 'dbmcp']))->toBeInstanceOf(ProjectRecord::class);
});

test('the database rejects a non-canonical project name', function (string $name) {
    expect(fn () => ProjectRecord::factory()->create(['name' => $name]))
        ->toThrow(QueryException::class, 'projects_name_canonical_check');
})->with(['DbMcp', ' dbmcp ', 'a--b', 'a__b', '']);

test('the database accepts whatever ProjectName::normalize produces', function (string $input) {
    $name = ProjectName::normalize($input);

    expect(ProjectRecord::factory()->create(['name' => $name]))->toBeInstanceOf(ProjectRecord::class);
})->with(['  DbMcp  ', 'My---Project', 'a___b', "\tFoo-_Bar\n", 'x--y__z', 'Ünïcode-Näme', 'plain']);

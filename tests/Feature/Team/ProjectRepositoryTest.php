<?php

use App\Memory\Domain\ProjectName;
use App\Memory\Domain\ProjectRepository;
use App\Models\User;
use App\Team\Application\ProvisionPersonalTeam;
use App\Team\Infrastructure\Persistence\ProjectRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function personalTeamOf(User $user): int
{
    return app(ProvisionPersonalTeam::class)->forUser($user->id);
}

test('getOrCreateId creates the project once and returns the same id after', function () {
    $teamId = personalTeamOf(User::factory()->create());
    $projects = app(ProjectRepository::class);

    $first = $projects->getOrCreateId($teamId, 'alpha');
    $second = $projects->getOrCreateId($teamId, 'alpha');

    expect($second)->toBe($first)
        ->and(ProjectRecord::where('team_id', $teamId)->where('name', 'alpha')->count())->toBe(1);
});

test('idFor never inserts and finds an existing project', function () {
    $teamId = personalTeamOf(User::factory()->create());
    $projects = app(ProjectRepository::class);

    expect($projects->idFor($teamId, 'alpha'))->toBeNull()
        ->and(ProjectRecord::count())->toBe(0);

    $id = $projects->getOrCreateId($teamId, 'alpha');

    expect($projects->idFor($teamId, 'alpha'))->toBe($id);
});

test('a name that normalization expands past 255 characters is stored', function () {
    $teamId = personalTeamOf(User::factory()->create());
    $name = ProjectName::normalize(str_repeat('İ', 255));

    $id = app(ProjectRepository::class)->getOrCreateId($teamId, $name);

    expect(mb_strlen($name))->toBeGreaterThan(255)
        ->and(ProjectRecord::find($id)->name)->toBe($name);
});

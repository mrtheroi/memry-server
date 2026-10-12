<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\GetMemory;
use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('find returns null for a memory of another team (S-T9.6)', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $memory = remember($owner, 'Team secret', 'Must never leak');

    expect((new EloquentMemoryRepository)->find(contextFor($stranger), $memory->id))->toBeNull()
        ->and((new EloquentMemoryRepository)->find(contextFor($owner), $memory->id)?->title)->toBe('Team secret');
});

function teamMemory(int $teamId, User $author, string $title, string $project = 'dbmcp'): Observation
{
    return (new EloquentMemoryRepository)->save(new Observation(
        userId: $author->id, sessionId: 's', type: 'decision', title: $title, content: 'sanctum content',
        project: $project, scope: 'project', topicKey: null, teamId: $teamId,
    ));
}

function sharedTeamOf(User $user): int
{
    return TeamRecord::factory()->create(['owner_id' => $user->id])->id;
}

function titles(array $observations): array
{
    return array_map(fn (Observation $o) => $o->title, $observations);
}

test('search returns only the caller team rows even for the same words (S-T9.2)', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    remember($alice, 'Alice sanctum', 'sanctum tokens');
    remember($bob, 'Bob sanctum', 'sanctum tokens');

    expect(titles((new EloquentMemoryRepository)->search(contextFor($alice), 'sanctum', 10)))->toBe(['Alice sanctum']);
});

test('a user in two teams sees only the context team rows (S-T9.7)', function () {
    $user = User::factory()->create();
    $personal = contextFor($user)->teamId;
    $shared = sharedTeamOf($user);
    teamMemory($personal, $user, 'In personal');
    $inShared = teamMemory($shared, $user, 'In shared');
    $repository = new EloquentMemoryRepository;
    $asShared = AccessContext::forTeam($shared, $user->id, Role::Owner, ProjectAccess::all());

    expect(titles($repository->search($asShared, 'sanctum', 10)))->toBe(['In shared'])
        ->and(titles($repository->search(contextFor($user), 'sanctum', 10)))->toBe(['In personal'])
        ->and($repository->find(contextFor($user), $inShared->id))->toBeNull()
        ->and($repository->find($asShared, $inShared->id)?->title)->toBe('In shared');
});

test('a project-restricted member sees only the granted project rows, never rows without a project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = contextFor($owner)->teamId;
    $allowed = teamMemory($team, $owner, 'Allowed project');
    $other = teamMemory($team, $owner, 'Other project', 'other');
    $noProject = (new EloquentMemoryRepository)->save(new Observation(
        userId: $owner->id, sessionId: 's', type: 'decision', title: 'No project', content: 'sanctum content',
        project: null, scope: 'personal', topicKey: null, teamId: $team,
    ));
    $projectId = DB::table('projects')->where('team_id', $team)->where('name', 'dbmcp')->value('id');
    $restricted = AccessContext::forTeam($team, $member->id, Role::Member, ProjectAccess::only($projectId));
    $repository = new EloquentMemoryRepository;

    expect(titles($repository->search($restricted, 'sanctum', 10)))->toBe(['Allowed project'])
        ->and($repository->find($restricted, $allowed->id)?->title)->toBe('Allowed project')
        ->and($repository->find($restricted, $other->id))->toBeNull()
        ->and($repository->find($restricted, $noProject->id))->toBeNull();
});

test('a denied context reads nothing and does not even query', function () {
    $user = User::factory()->create();
    $memory = remember($user, 'Anything', 'sanctum content');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $repository = new EloquentMemoryRepository;

    expect($repository->find(AccessContext::denied($user->id), $memory->id))->toBeNull()
        ->and($repository->search(AccessContext::denied($user->id), 'sanctum', 10))->toBe([])
        ->and($queries)->toBe([]);
});

test('the system context reads every team', function () {
    remember(User::factory()->create(), 'First team', 'sanctum content');
    remember(User::factory()->create(), 'Second team', 'sanctum content');

    expect(titles((new EloquentMemoryRepository)->search(AccessContext::system(), 'sanctum', 10)))->toHaveCount(2);
});

test('get-memory of a foreign id answers exactly like a missing id (S-T9.1)', function () {
    $user = User::factory()->create();
    $foreign = remember(User::factory()->create(), 'Foreign', 'Must never leak');

    MemoryServer::actingAs($user)->tool(GetMemory::class, ['id' => $foreign->id])->assertExactText('Memory not found.');
    MemoryServer::actingAs($user)->tool(GetMemory::class, ['id' => 999999])->assertExactText('Memory not found.');
});

test('the backfill guard migration refuses to run while rows lack a team', function () {
    $user = User::factory()->create();
    remember($user, 'Filled', 'x');
    $migration = require database_path('migrations/2026_10_12_100000_assert_team_backfill_complete.php');
    $migration->up();

    DB::table('observations')->insert(['user_id' => $user->id, 'session_id' => 's', 'type' => 't', 'title' => 'Legacy', 'content' => 'c', 'scope' => 'project', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_prompts')->insert(['user_id' => $user->id, 'session_id' => 's', 'content' => 'p', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Run memory:backfill-teams first: 2 rows without team_id');
});

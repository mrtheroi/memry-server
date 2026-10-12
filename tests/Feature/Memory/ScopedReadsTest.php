<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\GetMemory;
use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Application\BackfillTeams;
use App\Team\Domain\AccessContext;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;
use Illuminate\Database\Migrations\Migration;
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

function backfillMigration(): Migration
{
    return require database_path('migrations/2026_10_12_100000_backfill_teams_then_assert_complete.php');
}

function insertLegacyRows(User $user): void
{
    DB::table('observations')->insert(['user_id' => $user->id, 'session_id' => 's', 'type' => 't', 'title' => 'Legacy', 'content' => 'c', 'scope' => 'project', 'project' => 'dbmcp', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_prompts')->insert(['user_id' => $user->id, 'session_id' => 's', 'content' => 'p', 'created_at' => now(), 'updated_at' => now()]);
}

test('the upgrade migration backfills legacy rows instead of refusing to run', function () {
    $user = User::factory()->withoutPersonalTeam()->create();
    insertLegacyRows($user);

    backfillMigration()->up();

    expect(DB::table('observations')->whereNull('team_id')->count())->toBe(0)
        ->and(DB::table('user_prompts')->whereNull('team_id')->count())->toBe(0)
        ->and(DB::table('observations')->value('team_id'))->toBe(DB::table('teams')->where('owner_id', $user->id)->value('id'));
});

test('the upgrade migration still refuses when rows remain without a team after the backfill', function () {
    $user = User::factory()->create();
    insertLegacyRows($user);
    app()->bind(BackfillTeams::class, fn () => new class extends BackfillTeams
    {
        public function __construct() {}

        public function run(int $chunk, ?int $maxChunks = null): array
        {
            return ['teams' => 0, 'observations' => 0, 'user_prompts' => 0, 'tokens' => 0];
        }
    });

    expect(fn () => backfillMigration()->up())->toThrow(RuntimeException::class, 'Run memory:backfill-teams first: 2 rows without team_id');
});

test('the upgrade migration passes on a database with nothing to backfill', function () {
    remember(User::factory()->create(), 'Filled', 'x');

    expect(fn () => backfillMigration()->up())->not->toThrow(RuntimeException::class);
});

<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\GetContext;
use App\Mcp\Tools\GetMemory;
use App\Memory\Application\BuildProjectContext;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function actingOnTeam(User $user, int $teamId): User
{
    return $user->withAccessToken($user->createTeamToken('mcp', $teamId)->accessToken);
}

test('get-context of a user in two teams shows only the token team (S-T9.3)', function () {
    $user = User::factory()->create();
    $shared = sharedTeamOf($user);
    teamMemory(contextFor($user)->teamId, $user, 'In personal');
    teamMemory($shared, $user, 'In shared');

    MemoryServer::actingAs(actingOnTeam($user, $shared))->tool(GetContext::class, ['project' => 'dbmcp'])
        ->assertSee('In shared')
        ->assertDontSee('In personal');
});

test('every id listed by get-context is readable with get-memory under the same token', function () {
    $user = User::factory()->create();
    $shared = sharedTeamOf($user);
    teamMemory(contextFor($user)->teamId, $user, 'In personal');
    $ids = [teamMemory($shared, $user, 'First shared')->id, teamMemory($shared, $user, 'Second shared')->id];
    $acting = actingOnTeam($user, $shared);

    MemoryServer::actingAs($acting)->tool(GetContext::class, ['project' => 'dbmcp'])->assertSee(array_map(fn (int $id) => "#{$id}", $ids));

    foreach ($ids as $id) {
        MemoryServer::actingAs($acting)->tool(GetMemory::class, ['id' => $id])->assertSee('sanctum content');
    }
});

test('a project that exists only in another team answers exactly like an unknown one (S-T9.4)', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    teamMemory(contextFor($owner)->teamId, $owner, 'Secret alpha', 'alpha');

    MemoryServer::actingAs($stranger)->tool(GetContext::class, ['project' => 'alpha'])
        ->assertExactText('No context found for project alpha.');
});

test('a denied context reads no context and does not even query', function () {
    $user = User::factory()->create();
    remember($user, 'Anything', 'content');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(app(BuildProjectContext::class)(AccessContext::denied($user->id), 'dbmcp'))->toBe('No context found for project dbmcp.')
        ->and($queries)->toBe([]);
});

test('a project-restricted member sees the context of the granted projects only', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = contextFor($owner)->teamId;
    teamMemory($team, $owner, 'Allowed project');
    teamMemory($team, $owner, 'Other project', 'other');
    $projectId = DB::table('projects')->where('team_id', $team)->where('name', 'dbmcp')->value('id');
    $restricted = AccessContext::forTeam($team, $member->id, Role::Member, ProjectAccess::only($projectId));
    $build = app(BuildProjectContext::class);

    expect($build($restricted, 'dbmcp'))->toContain('Allowed project')
        ->and($build($restricted, 'other'))->toBe('No context found for project other.');
});

test('GET /api/context shows only the token team (S-T9.3)', function () {
    $user = User::factory()->create();
    $shared = sharedTeamOf($user);
    teamMemory(contextFor($user)->teamId, $user, 'In personal');
    teamMemory($shared, $user, 'In shared');
    $token = $user->createTeamToken('http', $shared)->plainTextToken;

    $this->withToken($token)->get('/api/context?project=dbmcp')
        ->assertOk()
        ->assertSee('In shared')
        ->assertDontSee('In personal');
});

test('GET /api/context of a project only another team has answers 200 with the no-context text', function () {
    $owner = User::factory()->create();
    teamMemory(contextFor($owner)->teamId, $owner, 'Secret alpha', 'alpha');
    $token = User::factory()->create()->createToken('http')->plainTextToken;

    $this->withToken($token)->get('/api/context?project=alpha')
        ->assertOk()
        ->assertContent('No context found for project alpha.');
});

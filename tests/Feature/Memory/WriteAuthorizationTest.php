<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\SaveMemory;
use App\Mcp\Tools\SavePrompt;
use App\Mcp\Tools\SessionSummary;
use App\Memory\Application\SaveObservation;
use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\QueryProjectRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
use App\Team\Domain\AccessDenied;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function memberOfPersonalTeam(): User
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = TeamRecord::where('owner_id', $owner->id)->where('personal_team', true)->sole();
    TeamUserRecord::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member]);
    $member->withAccessToken($member->createTeamToken('mcp', $team->id)->accessToken);

    return $member;
}

test('a member without project access cannot save a memory and nothing is written', function () {
    $member = memberOfPersonalTeam();

    $response = MemoryServer::actingAs($member)->tool(SaveMemory::class, [
        'session_id' => 's', 'type' => 'decision', 'title' => 'T', 'content' => 'C', 'project' => 'dbmcp',
    ]);

    $response->assertHasErrors();
    $response->assertExactError('Access denied to project dbmcp.');
    expect(DB::table('observations')->count())->toBe(0);
});

test('a member without project access cannot save a prompt and nothing is written', function () {
    $member = memberOfPersonalTeam();

    MemoryServer::actingAs($member)->tool(SavePrompt::class, ['session_id' => 's', 'project' => 'dbmcp', 'content' => 'hi'])
        ->assertExactError('Access denied to project dbmcp.');

    expect(DB::table('user_prompts')->count())->toBe(0);
});

function actAs(AccessContext $context): void
{
    app()->instance(AccessContextResolver::class, new class($context) implements AccessContextResolver
    {
        public function __construct(private AccessContext $context) {}

        public function resolve(?Authenticatable $user): AccessContext
        {
            return $this->context;
        }
    });
}

test('a member without project access cannot save a session summary and nothing is written', function () {
    $member = memberOfPersonalTeam();

    MemoryServer::actingAs($member)->tool(SessionSummary::class, ['session_id' => 's', 'project' => 'dbmcp', 'content' => 'done'])
        ->assertExactError('Access denied to project dbmcp.');

    expect(DB::table('observations')->count())->toBe(0);
});

test('a member granted only one project writes there and not to another', function () {
    $user = User::factory()->create();
    $teamId = TeamRecord::where('owner_id', $user->id)->value('id');
    $allowed = (new QueryProjectRepository)->getOrCreateId($teamId, 'alpha');
    (new QueryProjectRepository)->getOrCreateId($teamId, 'beta');
    actAs(AccessContext::forTeam($teamId, $user->id, Role::Member, ProjectAccess::only($allowed)));
    $arguments = fn (string $project) => ['session_id' => 's', 'type' => 'decision', 'title' => 'T', 'content' => 'C', 'project' => $project];

    MemoryServer::actingAs($user)->tool(SaveMemory::class, $arguments('alpha'))->assertHasNoErrors();
    MemoryServer::actingAs($user)->tool(SaveMemory::class, $arguments('beta'))->assertExactError('Access denied to project beta.');
    MemoryServer::actingAs($user)->tool(SaveMemory::class, $arguments('gamma'))->assertExactError('Access denied to project gamma.');

    expect(DB::table('observations')->pluck('project')->all())->toBe(['alpha'])
        ->and(DB::table('projects')->where('name', 'gamma')->exists())->toBeFalse();
});

test('a project-restricted member cannot save without a project', function () {
    $user = User::factory()->create();
    $teamId = TeamRecord::where('owner_id', $user->id)->value('id');
    actAs(AccessContext::forTeam($teamId, $user->id, Role::Member, ProjectAccess::only(1)));

    MemoryServer::actingAs($user)->tool(SaveMemory::class, ['session_id' => 's', 'type' => 'd', 'title' => 'T', 'content' => 'C'])
        ->assertExactError('Access denied.');

    expect(DB::table('observations')->count())->toBe(0);
});

test('a system context never writes memories', function () {
    $user = User::factory()->create();
    $observation = new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null);

    expect(fn () => app(SaveObservation::class)($observation, AccessContext::system()))->toThrow(AccessDenied::class);
    expect(DB::table('observations')->count())->toBe(0);
});

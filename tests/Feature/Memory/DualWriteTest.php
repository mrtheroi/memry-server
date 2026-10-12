<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\SaveMemory;
use App\Mcp\Tools\SavePrompt;
use App\Mcp\Tools\SessionSummary;
use App\Memory\Application\SaveObservation;
use App\Memory\Domain\Observation;
use App\Memory\Domain\UserPrompt;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function personalTeamId(User $user): int
{
    return TeamRecord::where('owner_id', $user->id)->value('id');
}

function memoryArguments(array $overrides = []): array
{
    return [
        'session_id' => 'session-1',
        'type' => 'decision',
        'title' => 'Use Postgres',
        'content' => 'tsvector',
        'project' => 'dbmcp',
        ...$overrides,
    ];
}

test('save-memory dual-writes team, project and authorship', function () {
    $user = User::factory()->create();

    $response = MemoryServer::actingAs($user)->tool(SaveMemory::class, memoryArguments());

    $teamId = personalTeamId($user);
    $row = DB::table('observations')->first();
    $response->assertExactText("Memory saved with id {$row->id}.");
    expect($row->team_id)->toBe($teamId)
        ->and($row->project_id)->toBe(DB::table('projects')->where('team_id', $teamId)->where('name', 'dbmcp')->value('id'))
        ->and($row->created_by)->toBe($user->id)
        ->and($row->updated_by)->toBe($user->id)
        ->and($row->user_id)->toBe($user->id);
});

test('a topic_key upsert sets updated_by and never changes created_by', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $save = app(SaveObservation::class);
    $teamId = personalTeamId($user);
    $observation = fn (string $content) => new Observation($user->id, 's', 'decision', 'T', $content, 'dbmcp', 'project', 'k', teamId: $teamId);

    $context = AccessContext::forTeam($teamId, $user->id, Role::Owner, ProjectAccess::all());

    $created = $save($observation('v1'), $context);
    DB::table('observations')->where('id', $created->id)->update(['created_by' => $other->id, 'updated_by' => $other->id]);
    $save($observation('v2'), $context);

    $row = DB::table('observations')->sole();
    expect($row->created_by)->toBe($other->id)
        ->and($row->updated_by)->toBe($user->id)
        ->and($row->content)->toBe('v2');
});

test('an identical re-save leaves updated_at untouched', function () {
    $user = User::factory()->create();
    $save = app(SaveObservation::class);
    $observation = new Observation($user->id, 's', 'decision', 'T', 'v1', ' Alpha ', 'project', 'k', teamId: personalTeamId($user));
    $context = AccessContext::forTeam(personalTeamId($user), $user->id, Role::Owner, ProjectAccess::all());

    $this->travelTo('2026-09-01 10:00:00');
    $save($observation, $context);
    $this->travelTo('2026-09-02 10:00:00');
    $save($observation, $context);

    expect(DB::table('observations')->sole()->updated_at)->toBe('2026-09-01 10:00:00');
});

test('a normalized project row is created while the observation keeps its normalized text', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SaveMemory::class, memoryArguments(['project' => ' Alpha ']))->assertOk();

    $row = DB::table('observations')->sole();
    expect($row->project)->toBe('alpha')
        ->and(DB::table('projects')->where('id', $row->project_id)->value('name'))->toBe('alpha');
});

test('a null project leaves project_id NULL and creates no project row', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SaveMemory::class, memoryArguments(['project' => null]))->assertOk();

    $row = DB::table('observations')->sole();
    expect($row->team_id)->toBe(personalTeamId($user))
        ->and($row->project_id)->toBeNull()
        ->and(DB::table('projects')->count())->toBe(0);
});

test('a user without a team still saves, with the team columns NULL and the same response', function () {
    $user = User::factory()->withoutPersonalTeam()->create();

    $response = MemoryServer::actingAs($user)->tool(SaveMemory::class, memoryArguments());

    $row = DB::table('observations')->sole();
    $response->assertExactText("Memory saved with id {$row->id}.");
    expect([$row->team_id, $row->project_id, $row->created_by, $row->updated_by])->each->toBeNull()
        ->and($row->user_id)->toBe($user->id);
});

test('a legacy NULL-team token resolves to the personal team and dual-writes', function () {
    $user = User::factory()->create();
    $token = $user->createToken('legacy')->plainTextToken;

    $this->withToken($token)->postJson('/mcp/memory', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'save-memory', 'arguments' => memoryArguments()],
    ])->assertOk();

    expect(DB::table('observations')->sole()->team_id)->toBe(personalTeamId($user));
});

test('save-prompt dual-writes team and project', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SavePrompt::class, ['session_id' => 's', 'project' => ' Alpha ', 'content' => 'hi'])
        ->assertExactText('Prompt saved.');

    $row = DB::table('user_prompts')->sole();
    expect($row->team_id)->toBe(personalTeamId($user))
        ->and(DB::table('projects')->where('id', $row->project_id)->value('name'))->toBe('alpha')
        ->and($row->project)->toBe('alpha');
});

test('session-summary dual-writes team and authorship', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SessionSummary::class, ['session_id' => 's', 'project' => 'dbmcp', 'content' => 'done'])
        ->assertExactText('Session summary saved.');

    $row = DB::table('observations')->sole();
    expect($row->team_id)->toBe(personalTeamId($user))
        ->and($row->project_id)->not->toBeNull()
        ->and($row->created_by)->toBe($user->id);
});

test('merge-projects moves project_id per team, keeps rows without a team NULL and the source project', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    $legacy = User::factory()->withoutPersonalTeam()->create();
    $observation = fn (User $user, ?int $teamId, string $project) => (new EloquentMemoryRepository)->save(
        new Observation($user->id, 's', 'decision', 'T', 'C', $project, 'project', null, teamId: $teamId),
    );
    $prompt = fn (User $user, ?int $teamId, string $project) => (new EloquentPromptRepository)->save(
        new UserPrompt($user->id, 's', $project, 'P', teamId: $teamId),
    );
    foreach ([$ada, $bob] as $user) {
        $observation($user, personalTeamId($user), 'old');
        $prompt($user, personalTeamId($user), 'old');
    }
    $observation($legacy, null, 'old');
    $prompt($legacy, null, 'old');

    $this->artisan('memory:merge-projects', ['from' => 'old', 'to' => ' New '])
        ->expectsOutputToContain('Moved 3 observations and 3 prompts from old to new.')
        ->assertSuccessful();

    foreach (['observations', 'user_prompts'] as $table) {
        foreach ([$ada, $bob] as $user) {
            $teamId = personalTeamId($user);
            $row = DB::table($table)->where('user_id', $user->id)->sole();
            expect($row->project)->toBe('new')
                ->and($row->project_id)->toBe(DB::table('projects')->where('team_id', $teamId)->where('name', 'new')->value('id'));
        }
        $row = DB::table($table)->where('user_id', $legacy->id)->sole();
        expect($row->project)->toBe('new')->and($row->project_id)->toBeNull();
    }
    expect(DB::table('projects')->where('name', 'old')->count())->toBe(2)
        ->and(DB::table('projects')->where('name', 'new')->count())->toBe(2);
});

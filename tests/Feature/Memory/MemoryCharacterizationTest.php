<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\GetContext;
use App\Mcp\Tools\GetMemory;
use App\Mcp\Tools\SaveMemory;
use App\Mcp\Tools\SavePrompt;
use App\Mcp\Tools\SearchMemory;
use App\Mcp\Tools\SessionSummary;
use App\Memory\Application\BuildProjectContext;
use App\Memory\Application\SaveObservation;
use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Characterization tests (BP-1, BP-3, BP-4, BP-6, BP-15): they pin today's
 * behavior and passed on first run against unchanged production code.
 */

uses(RefreshDatabase::class);

function observation(User $user, array $overrides = []): Observation
{
    return new Observation(...[
        'userId' => $user->id,
        'sessionId' => 'session-1',
        'type' => 'decision',
        'title' => 'Auth model',
        'content' => 'Sessions with cookies',
        'project' => 'dbmcp',
        'scope' => 'project',
        'topicKey' => 'architecture/auth',
        ...$overrides,
    ]);
}

test('save-memory answers with the exact saved text and the same id on a topic key upsert', function () {
    $user = User::factory()->create();
    $arguments = ['session_id' => 's', 'type' => 'decision', 'title' => 'Auth', 'content' => 'A', 'topic_key' => 'k'];

    MemoryServer::actingAs($user)->tool(SaveMemory::class, $arguments);

    $id = (int) DB::table('observations')->value('id');
    MemoryServer::actingAs($user)->tool(SaveMemory::class, [...$arguments, 'content' => 'B'])
        ->assertExactText("Memory saved with id {$id}.");
    MemoryServer::actingAs($user)->tool(SaveMemory::class, [...$arguments, 'topic_key' => 'new'])
        ->assertExactText('Memory saved with id '.DB::table('observations')->max('id').'.');
});

test('get-memory answers with the exact memory text', function () {
    $user = User::factory()->create();
    $memory = remember($user, 'Auth model', "Line one\nLine two");

    MemoryServer::actingAs($user)->tool(GetMemory::class, ['id' => $memory->id])
        ->assertExactText("#{$memory->id} [decision] Auth model\nLine one\nLine two");
});

test('get-memory answers byte-identically for another user memory and a nonexistent id', function () {
    $user = User::factory()->create();
    $foreign = remember(User::factory()->create(), 'Secret', 'Must never leak');

    MemoryServer::actingAs($user)->tool(GetMemory::class, ['id' => $foreign->id])->assertExactText('Memory not found.');
    MemoryServer::actingAs($user)->tool(GetMemory::class, ['id' => 999999])->assertExactText('Memory not found.');
});

test('search-memory answers with the exact texts', function () {
    $user = User::factory()->create();
    $first = remember($user, 'Sanctum tokens are hashed', 'Stored with SHA-256');
    $second = remember($user, 'Deploy checklist', 'Rotate the Sanctum tokens');

    MemoryServer::actingAs($user)->tool(SearchMemory::class, ['query' => 'sanctum tokens'])
        ->assertExactText("#{$first->id} [decision] Sanctum tokens are hashed\nStored with SHA-256\n\n#{$second->id} [decision] Deploy checklist\nRotate the Sanctum tokens");
    MemoryServer::actingAs($user)->tool(SearchMemory::class, ['query' => 'nothing'])->assertExactText('No memories found.');
});

test('get-context answers with the exact full text', function () {
    $user = User::factory()->create();
    $this->travelTo('2026-09-01 10:00:00');
    $older = remember($user, 'Session one', 'First summary', type: 'session_summary');
    $this->travelTo('2026-09-02 10:00:00');
    $newest = remember($user, 'Session two', "Latest\nsummary", type: 'session_summary');
    $knowledge = remember($user, 'Auth model', "Sanctum\nbearer tokens", topicKey: 'architecture/auth');
    $recent = remember($user, 'Fixed flaky test', 'Root cause: clock');

    MemoryServer::actingAs($user)->tool(GetContext::class, ['project' => 'DbMcp'])->assertExactText(
        "## Recent sessions\n#{$newest->id} [session_summary] Session two\nLatest\nsummary\n\n"
        ."- #{$older->id} [session_summary] Session one (2026-09-01 10:00): First summary\n\n"
        ."## Project knowledge\n- #{$knowledge->id} [decision] Auth model: Sanctum bearer tokens\n\n"
        ."## Recent memories\n- #{$recent->id} [decision] Fixed flaky test\n\n"
        .'Use get-memory with an id to read a memory in full.'
    );
});

test('get-context answers with the exact empty text for an unknown project', function () {
    MemoryServer::actingAs(User::factory()->create())->tool(GetContext::class, ['project' => 'Alpha'])
        ->assertExactText('No context found for project alpha.');
});

test('building the context of a null project fails with a TypeError today instead of an empty text', function () {
    $user = User::factory()->create();

    // BUG-LOOKING (pinned, not fixed): the spec expects "No context found for project .".
    // The tool cannot reach this path (project is required), only the use case can.
    expect(fn () => app(BuildProjectContext::class)($user->id, null))->toThrow(TypeError::class);
});

test('save-prompt and session-summary answer with the exact texts', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SavePrompt::class, ['session_id' => 's', 'content' => 'Do it'])
        ->assertExactText('Prompt saved.');
    MemoryServer::actingAs($user)->tool(SessionSummary::class, ['session_id' => 's', 'project' => 'dbmcp', 'content' => 'Done'])
        ->assertExactText('Session summary saved.');
});

test('save-prompt without a project stores a null project', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SavePrompt::class, ['session_id' => 's', 'content' => 'Do it'])->assertOk();

    $this->assertDatabaseHas('user_prompts', ['user_id' => $user->id, 'project' => null]);
});

test('every tool writes the project scope', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SaveMemory::class, ['session_id' => 's', 'type' => 't', 'title' => 'A', 'content' => 'C'])->assertOk();
    MemoryServer::actingAs($user)->tool(SessionSummary::class, ['session_id' => 's', 'project' => 'p', 'content' => 'C'])->assertOk();

    expect(DB::table('observations')->pluck('scope')->all())->toBe(['project', 'project']);
});

test('the topic key is scoped by scope, while search and context ignore the scope', function () {
    $user = User::factory()->create();
    $repository = new EloquentMemoryRepository;
    $repository->save(observation($user, ['scope' => 'personal', 'title' => 'Personal sanctum note']));

    expect($repository->findByTopicKey($user->id, 'dbmcp', 'project', 'architecture/auth'))->toBeNull()
        ->and($repository->findByTopicKey($user->id, 'dbmcp', 'personal', 'architecture/auth'))->not->toBeNull()
        ->and(searchFor($user, 'sanctum'))->toHaveCount(1);

    app(SaveObservation::class)(observation($user, ['title' => 'Project note']));
    $this->assertDatabaseCount('observations', 2);

    expect(app(BuildProjectContext::class)($user->id, 'dbmcp'))->toContain('Personal sanctum note', 'Project note');
});

test('the repository normalizes the project and stores a blank one as null', function () {
    $user = User::factory()->create();

    remember($user, 'Spelled', 'C', project: '  Db--Mcp__App ');
    remember($user, 'Blank', 'C', project: '   ');

    $this->assertDatabaseHas('observations', ['title' => 'Spelled', 'project' => 'db-mcp_app']);
    $this->assertDatabaseHas('observations', ['title' => 'Blank', 'project' => null]);
});

test('a topic key upsert matches a null project against a null project', function () {
    $user = User::factory()->create();
    $save = app(SaveObservation::class);

    $first = $save(observation($user, ['project' => null]));
    $second = $save(observation($user, ['project' => null, 'content' => 'Changed']));

    expect($second->id)->toBe($first->id);
    $this->assertDatabaseCount('observations', 1);
});

test('an upsert that changes the content moves updated_at and keeps the id, one that changes nothing does not', function () {
    $user = User::factory()->create();
    $save = app(SaveObservation::class);

    $this->travelTo('2026-09-01 10:00:00');
    $first = $save(observation($user));
    $this->travelTo('2026-09-02 10:00:00');
    $same = $save(observation($user));

    expect($same->id)->toBe($first->id)
        ->and(recall($user, $first->id)->updatedAt->format('Y-m-d H:i:s'))->toBe('2026-09-01 10:00:00');

    $this->travelTo('2026-09-03 10:00:00');
    $save(observation($user, ['content' => 'Changed']));

    expect(recall($user, $first->id)->updatedAt->format('Y-m-d H:i:s'))->toBe('2026-09-03 10:00:00');
});

test('two interleaved upserts of the same new topic key leave two rows today, and a later upsert still creates no third', function () {
    $user = User::factory()->create();
    $repository = new EloquentMemoryRepository;
    $lookup = fn () => $repository->findByTopicKey($user->id, 'dbmcp', 'project', 'architecture/auth');

    // Both writers look up before either saves: the race the code does not guard against.
    $seenByA = $lookup();
    $seenByB = $lookup();
    $repository->save(observation($user, ['content' => 'Writer A']));
    $repository->save(observation($user, ['content' => 'Writer B']));

    expect($seenByA)->toBeNull()->and($seenByB)->toBeNull();
    $this->assertDatabaseCount('observations', 2);

    app(SaveObservation::class)(observation($user, ['content' => 'Writer C']));

    $this->assertDatabaseCount('observations', 2);
});

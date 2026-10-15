<?php

use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\ProjectAccessPolicy;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
 * Exact response text of a successful MCP tool result, for goldens
 * (assertSee only matches substrings). An error result never passes.
 */
TestResponse::macro('assertExactText', function (string $text) {
    /** @var TestResponse $this */
    $this->assertHasNoErrors();
    Assert::assertSame([$text], $this->content(), 'The MCP response text does not match exactly.');

    return $this;
});

/*
 * Exact text of an MCP error result: pins that today's response IS an error.
 */
TestResponse::macro('assertExactError', function (string $text) {
    /** @var TestResponse $this */
    $this->assertHasErrors();
    Assert::assertSame([$text], $this->errors(), 'The MCP error text does not match exactly.');

    return $this;
});

/*
 * Thin wrappers over the repositories, so a later slice that changes their
 * signatures only touches these helpers, never the test bodies.
 */
function contextFor(User $user): AccessContext
{
    return app(AccessContextResolver::class)->resolve($user);
}

/*
 * Characterization tests save through the repository without a team, so they read back as
 * legacy rows: system context, narrowed to the user, as the reads were before team scoping.
 * Tenant isolation is covered with real contexts in ScopedReadsTest.
 */
function recall(User $user, int $id): ?Observation
{
    return (new EloquentMemoryRepository)->find(contextFor($user), $id);
}

/**
 * @return list<Observation>
 */
function searchFor(User $user, string $query, int $limit = 10, ?string $project = null): array
{
    return (new EloquentMemoryRepository)->search(contextFor($user), $query, $limit, $project);
}

function remember(User $user, string $title, string $content, string $project = 'dbmcp', string $type = 'decision', ?string $topicKey = null): Observation
{
    return (new EloquentMemoryRepository)->save(new Observation(
        userId: $user->id,
        sessionId: 'session-1',
        type: $type,
        title: $title,
        content: $content,
        project: $project,
        scope: 'project',
        topicKey: $topicKey,
        teamId: contextFor($user)->teamId,
    ));
}

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

/*
 * observations.team_id and user_prompts.team_id are NOT NULL. Tests of the backfill and of the
 * legacy-row contract need rows without a team, so they relax both columns for the test (the
 * DDL rolls back with the test transaction).
 */
function allowRowsWithoutTeam(): void
{
    foreach (['set_observations_team_id_not_null', 'set_user_prompts_team_id_not_null'] as $name) {
        (require collect(glob(database_path("migrations/*_{$name}.php")))->sole())->down();
    }
}

/*
 * Dashboard read helpers.
 */
function bearerOf(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createTeamToken('cli', contextFor($user)->teamId)->plainTextToken];
}

/*
 * The app instance is reused across requests inside one test, so the guard would keep the first caller.
 */
function readAs(array $headers, string $uri): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders($headers)->getJson($uri);
}

function deleteAs(array $headers, string $uri): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders($headers)->deleteJson($uri);
}

/*
 * The shipped policy grants members no project yet; pin one to exercise the filter. Null grants every project.
 *
 * @param  list<int>|null  $projectIds
 */
function pinMemberAccess(?array $projectIds = null): void
{
    app()->bind(ProjectAccessPolicy::class, fn () => new class($projectIds) implements ProjectAccessPolicy
    {
        public function __construct(private ?array $projectIds) {}

        public function for(Role $role, int $teamId, int $userId): ProjectAccess
        {
            return $role === Role::Member && $this->projectIds !== null ? ProjectAccess::only(...$this->projectIds) : ProjectAccess::all();
        }
    });
}

/*
 * A dashboard session for the user: a stateful origin plus the web guard, as the SPA cookie resolves to.
 */
function sessionOf(User $user): TestCase
{
    config(['sanctum.stateful' => ['app.memry.test']]);
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'web')->withHeaders(['Origin' => 'https://app.memry.test', 'Referer' => 'https://app.memry.test/']);
}

function personalMemory(User $author, string $title, string $project = 'dbmcp'): Observation
{
    return (new EloquentMemoryRepository)->save(new Observation(
        userId: $author->id, sessionId: 's', type: 'decision', title: $title, content: 'private notes',
        project: $project, scope: 'personal', topicKey: null, teamId: contextFor($author)->teamId,
    ));
}

/*
 * OTLP/HTTP JSON builders shared by the usage parser and ingest tests.
 */
function attrs(array $pairs): array
{
    return collect($pairs)->map(fn ($v, $k) => ['key' => $k, 'value' => is_int($v) ? ['intValue' => (string) $v] : ['stringValue' => $v]])->values()->all();
}

function sumMetric(string $name, array $dataPoints, mixed $temporality = 1): array
{
    return ['name' => $name, 'unit' => 'tokens', 'sum' => ['aggregationTemporality' => $temporality, 'isMonotonic' => true, 'dataPoints' => $dataPoints]];
}

function otlp(array ...$metrics): array
{
    return ['resourceMetrics' => [[
        'resource' => ['attributes' => attrs(['service.name' => 'claude-code'])],
        'scopeMetrics' => [['scope' => ['name' => 'com.anthropic.claude_code'], 'metrics' => $metrics]],
    ]]];
}

function tokenPoint(string $type, string $value, string $model = 'claude-opus-4-7', string $start = '1760000000000000000', string $end = '1760000060000000000'): array
{
    return [
        'attributes' => attrs(['type' => $type, 'model' => $model, 'query_source' => 'main', 'session.id' => 'sess-1']),
        'startTimeUnixNano' => $start,
        'timeUnixNano' => $end,
        'asInt' => $value,
    ];
}

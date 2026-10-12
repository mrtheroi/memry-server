<?php

use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
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
    $found = (new EloquentMemoryRepository)->find(AccessContext::system(), $id);

    return $found?->userId === $user->id ? $found : null;
}

/**
 * @return list<Observation>
 */
function searchFor(User $user, string $query, int $limit = 10, ?string $project = null): array
{
    $found = (new EloquentMemoryRepository)->search(AccessContext::system(), $query, PHP_INT_MAX, $project);

    return array_slice(array_values(array_filter($found, fn (Observation $o) => $o->userId === $user->id)), 0, $limit);
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

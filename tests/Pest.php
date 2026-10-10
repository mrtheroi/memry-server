<?php

use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
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
 * Exact response text of an MCP tool, for goldens (assertSee only matches substrings).
 */
TestResponse::macro('assertExactText', function (string $text) {
    /** @var TestResponse $this */
    Assert::assertSame([$text], $this->content(), 'The MCP response text does not match exactly.');

    return $this;
});

/*
 * Thin wrappers over the repositories, so a later slice that changes their
 * signatures only touches these helpers, never the test bodies.
 */
function contextFor(User $user): int
{
    return $user->id;
}

function recall(User $user, int $id): ?Observation
{
    return (new EloquentMemoryRepository)->find($id);
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
    ));
}

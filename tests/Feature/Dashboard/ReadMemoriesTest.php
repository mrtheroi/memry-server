<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the memory list answers 401 without credentials', function () {
    $this->getJson('/api/memories')->assertUnauthorized();
});

test('the memory list returns the item fields, an excerpt of the content and the pagination meta', function () {
    $ada = User::factory()->create();
    $long = str_repeat('word ', 80)."\n\nsecond paragraph";
    remember($ada, 'A decision', $long, 'alpha', 'decision', 'arch/topic');

    $response = readAs(bearerOf($ada), '/api/memories')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'title', 'type', 'project', 'scope', 'topic_key', 'excerpt', 'created_at', 'updated_at'])
        ->and($response->json('data.0'))->not->toHaveKey('content')
        ->and($response->json('data.0.title'))->toBe('A decision')
        ->and($response->json('data.0.project'))->toBe('alpha')
        ->and($response->json('data.0.scope'))->toBe('project')
        ->and($response->json('data.0.topic_key'))->toBe('arch/topic')
        ->and(mb_strlen($response->json('data.0.excerpt')))->toBeLessThanOrEqual(201)
        ->and($response->json('data.0.excerpt'))->toStartWith('word word')->not->toContain("\n")
        ->and($response->json('data.0.updated_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($response->json('meta'))->toBe(['next_cursor' => null, 'prev_cursor' => null, 'per_page' => 20]);
});

test('per_page defaults to 20 and accepts up to 100', function () {
    $ada = User::factory()->create();
    foreach (range(1, 21) as $n) {
        remember($ada, "Memory {$n}", 'content');
    }

    expect(readAs(bearerOf($ada), '/api/memories')->assertOk()->json('data'))->toHaveCount(20)
        ->and(readAs(bearerOf($ada), '/api/memories?per_page=100')->assertOk()->json('meta.per_page'))->toBe(100)
        ->and(readAs(bearerOf($ada), '/api/memories?per_page=100')->json('data'))->toHaveCount(21);
});

test('an invalid per_page or cursor answers 422', function (string $query, string $field) {
    $ada = User::factory()->create();
    remember($ada, 'One', 'content');

    readAs(bearerOf($ada), "/api/memories?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero' => ['per_page=0', 'per_page'],
    'above the max' => ['per_page=101', 'per_page'],
    'not a number' => ['per_page=many', 'per_page'],
    'not base64' => ['cursor=%25%25%25', 'cursor'],
    'foreign payload' => ['cursor='.'eyJmb28iOiJiYXIifQ', 'cursor'],
    'injected value' => ['cursor='.'eyJ1cGRhdGVkX2F0Ijoibm90IGEgZGF0ZSIsImlkIjoxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9', 'cursor'],
]);

test('pages follow updated_at then id, stay stable under equal timestamps and chain through next_cursor', function () {
    $ada = User::factory()->create();
    $ids = collect(range(1, 5))->map(fn (int $n) => remember($ada, "Memory {$n}", 'content')->id);
    DB::table('observations')->update(['updated_at' => '2026-10-01 10:00:00']);
    DB::table('observations')->where('id', $ids[0])->update(['updated_at' => '2026-10-02 10:00:00']);

    $first = readAs(bearerOf($ada), '/api/memories?per_page=2')->assertOk();
    $second = readAs(bearerOf($ada), '/api/memories?per_page=2&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk();
    $third = readAs(bearerOf($ada), '/api/memories?per_page=2&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk();
    $back = readAs(bearerOf($ada), '/api/memories?per_page=2&cursor='.urlencode($second->json('meta.prev_cursor')))->assertOk();

    // Newest update first (memory 1), then the equal timestamps by descending id.
    expect(array_column($first->json('data'), 'id'))->toBe([$ids[0], $ids[4]])
        ->and(array_column($second->json('data'), 'id'))->toBe([$ids[3], $ids[2]])
        ->and(array_column($third->json('data'), 'id'))->toBe([$ids[1]])
        ->and($third->json('meta.next_cursor'))->toBeNull()
        ->and($first->json('meta.prev_cursor'))->toBeNull()
        ->and(array_column($back->json('data'), 'id'))->toBe(array_column($first->json('data'), 'id'));
});

test('the type and project filters narrow the list', function () {
    $ada = User::factory()->create();
    remember($ada, 'Summary', 'content', 'alpha', 'session_summary');
    remember($ada, 'Decision', 'content', 'alpha', 'decision');
    remember($ada, 'Other project', 'content', 'beta', 'session_summary');

    $titles = fn (string $query) => array_column(readAs(bearerOf($ada), "/api/memories?{$query}")->assertOk()->json('data'), 'title');

    expect($titles('type=session_summary&project=alpha'))->toBe(['Summary'])
        ->and($titles('project=BETA'))->toBe(['Other project'])
        ->and($titles('type=session_summary'))->toHaveCount(2);
});

test('the list hides other teams, ungranted projects and other members personal memories', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $team = contextFor($owner)->teamId;
    TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $member->id, 'role' => Role::Member]);
    teamMemory($team, $owner, 'Granted', 'granted');
    teamMemory($team, $owner, 'Ungranted', 'ungranted');
    personalMemory($owner, 'Owner private', 'granted');
    remember($stranger, 'Foreign', 'content', 'granted');
    pinMemberAccess([DB::table('projects')->where('name', 'granted')->value('id')]);
    $memberHeaders = ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken];

    $titles = fn (array $headers) => array_column(readAs($headers, '/api/memories')->assertOk()->json('data'), 'title');

    expect($titles($memberHeaders))->toBe(['Granted'])
        ->and($titles(bearerOf($owner)))->toEqualCanonicalizing(['Granted', 'Ungranted', 'Owner private']);
});

test('the memory list works under a dashboard session', function () {
    $ada = User::factory()->create();
    remember($ada, 'One', 'content');

    sessionOf($ada)->getJson('/api/memories')->assertOk()->assertJsonPath('data.0.title', 'One');
});

test('a project named 0 filters the list instead of being treated as no filter', function () {
    $ada = User::factory()->create();
    remember($ada, 'In zero', 'content', '0');
    remember($ada, 'Elsewhere', 'content', 'alpha');

    expect(array_column(readAs(bearerOf($ada), '/api/memories?project=0')->assertOk()->json('data'), 'title'))->toBe(['In zero']);
});

test('a cursor with an impossible date answers 422, not a server error', function () {
    $ada = User::factory()->create();
    remember($ada, 'One', 'content');
    $forged = rtrim(strtr(base64_encode(json_encode(['updated_at' => '2026-02-30 10:00:00', 'id' => 1, '_pointsToNextItems' => true])), '+/', '-_'), '=');

    readAs(bearerOf($ada), "/api/memories?cursor={$forged}")->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

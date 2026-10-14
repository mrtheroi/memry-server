<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the search answers 401 without credentials', function () {
    $this->getJson('/api/memories/search?q=sanctum')->assertUnauthorized();
});

test('the search ranks like the MCP search and returns plain items without a cursor', function () {
    $ada = User::factory()->create();
    remember($ada, 'Passing mention', 'we talked about auth and, once, sanctum', 'alpha');
    remember($ada, 'Sanctum sanctum', 'sanctum sanctum sanctum tokens', 'alpha');
    remember($ada, 'Unrelated', 'nothing here', 'alpha');

    $response = readAs(bearerOf($ada), '/api/memories/search?q=sanctum')->assertOk();

    expect(array_column($response->json('data'), 'title'))->toBe(array_column(searchFor($ada, 'sanctum'), 'title'))
        ->and(array_column($response->json('data'), 'title'))->toBe(['Sanctum sanctum', 'Passing mention'])
        ->and($response->json('data.0'))->toHaveKeys(['id', 'title', 'type', 'project', 'scope', 'topic_key', 'excerpt', 'created_at', 'updated_at'])
        ->and($response->json())->not->toHaveKey('meta');
});

test('the search validates q and limit', function (string $query, string $field) {
    $ada = User::factory()->create();

    readAs(bearerOf($ada), "/api/memories/search?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing q' => ['', 'q'],
    'blank q' => ['q=%20%20', 'q'],
    'q too long' => ['q='.str_repeat('a', 201), 'q'],
    'limit zero' => ['q=x&limit=0', 'limit'],
    'limit above max' => ['q=x&limit=51', 'limit'],
]);

test('limit caps the results and project narrows them', function () {
    $ada = User::factory()->create();
    foreach (range(1, 3) as $n) {
        remember($ada, "Alpha {$n}", 'sanctum notes', 'alpha');
    }
    remember($ada, 'Beta', 'sanctum notes', 'beta');

    expect(readAs(bearerOf($ada), '/api/memories/search?q=sanctum&limit=2')->assertOk()->json('data'))->toHaveCount(2)
        ->and(readAs(bearerOf($ada), '/api/memories/search?q=sanctum')->json('data'))->toHaveCount(4)
        ->and(array_column(readAs(bearerOf($ada), '/api/memories/search?q=sanctum&project=beta')->json('data'), 'title'))->toBe(['Beta']);
});

test('the search hides other teams, ungranted projects and other members personal memories', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $team = contextFor($owner)->teamId;
    TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $member->id, 'role' => Role::Member]);
    teamMemory($team, $owner, 'Granted', 'granted');
    teamMemory($team, $owner, 'Ungranted', 'ungranted');
    personalMemory($owner, 'Owner private', 'granted');
    remember($stranger, 'Foreign', 'sanctum content', 'granted');
    pinMemberAccess([DB::table('projects')->where('name', 'granted')->value('id')]);
    $memberHeaders = ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken];
    DB::table('observations')->where('title', 'Owner private')->update(['content' => 'sanctum private']);

    $titles = fn (array $headers) => array_column(readAs($headers, '/api/memories/search?q=sanctum')->assertOk()->json('data'), 'title');

    expect($titles($memberHeaders))->toBe(['Granted'])
        ->and($titles(bearerOf($owner)))->toEqualCanonicalizing(['Granted', 'Ungranted', 'Owner private']);
});

test('the search works under a dashboard session', function () {
    $ada = User::factory()->create();
    remember($ada, 'Sanctum', 'sanctum');

    sessionOf($ada)->getJson('/api/memories/search?q=sanctum')->assertOk()->assertJsonPath('data.0.title', 'Sanctum');
});

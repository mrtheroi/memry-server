<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the memory detail answers 401 without credentials', function () {
    $this->getJson('/api/memories/1')->assertUnauthorized();
});

test('the memory detail returns the item fields with the full content', function () {
    $ada = User::factory()->create();
    $content = str_repeat("line of content\n", 60);
    $memory = remember($ada, 'Full', $content, 'alpha', 'decision', 'arch/full');

    $response = readAs(bearerOf($ada), "/api/memories/{$memory->id}")->assertOk();

    expect($response->json('data.content'))->toBe($content)
        ->and($response->json('data.id'))->toBe($memory->id)
        ->and($response->json('data'))->toHaveKeys(['title', 'type', 'project', 'scope', 'topic_key', 'excerpt', 'created_at', 'updated_at', 'content']);
});

test('a memory the caller cannot see answers the exact same 404 as a missing id', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $team = contextFor($owner)->teamId;
    TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $member->id, 'role' => Role::Member]);
    $foreign = remember($stranger, 'Foreign', 'content', 'alpha');
    $ungranted = teamMemory($team, $owner, 'Ungranted', 'ungranted');
    $granted = teamMemory($team, $owner, 'Granted', 'granted');
    $private = personalMemory($owner, 'Owner private', 'granted');
    pinMemberAccess([DB::table('projects')->where('name', 'granted')->value('id')]);
    $memberHeaders = ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken];

    $missing = readAs($memberHeaders, '/api/memories/999999')->assertNotFound();

    foreach ([$foreign, $ungranted, $private] as $hidden) {
        $response = readAs($memberHeaders, "/api/memories/{$hidden->id}")->assertNotFound();

        expect($response->getContent())->toBe($missing->getContent());
    }

    readAs($memberHeaders, "/api/memories/{$granted->id}")->assertOk();
    readAs(bearerOf($owner), "/api/memories/{$private->id}")->assertOk();
});

test('a non-numeric id is a 404 too', function () {
    $ada = User::factory()->create();

    readAs(bearerOf($ada), '/api/memories/abc')->assertNotFound();
});

test('the memory detail works under a dashboard session', function () {
    $ada = User::factory()->create();
    $memory = remember($ada, 'One', 'content');

    sessionOf($ada)->getJson("/api/memories/{$memory->id}")->assertOk()->assertJsonPath('data.title', 'One');
});

test('an id beyond the integer range is a 404, not a server error', function () {
    $ada = User::factory()->create();

    readAs(bearerOf($ada), '/api/memories/99999999999999999999')->assertNotFound();
});

test('a user without a team reads nothing: empty collections and a 404 for any id', function () {
    $owner = User::factory()->create();
    $memory = remember($owner, 'Sanctum', 'sanctum');
    $teamless = User::factory()->withoutPersonalTeam()->create();
    $headers = ['Authorization' => 'Bearer '.$teamless->createToken('cli')->plainTextToken];

    expect(readAs($headers, '/api/projects')->assertOk()->json('data'))->toBe([])
        ->and(readAs($headers, '/api/memories')->assertOk()->json('data'))->toBe([])
        ->and(readAs($headers, '/api/memories/search?q=sanctum')->assertOk()->json('data'))->toBe([]);
    readAs($headers, "/api/memories/{$memory->id}")->assertNotFound();
});

test('ids up to the bigint maximum are looked up and anything above is the same 404', function () {
    $ada = User::factory()->create();
    $memory = remember($ada, 'Huge id', 'content');
    DB::table('observations')->where('id', $memory->id)->update(['id' => PHP_INT_MAX]);
    $missing = readAs(bearerOf($ada), '/api/memories/999999')->assertNotFound();

    readAs(bearerOf($ada), '/api/memories/'.PHP_INT_MAX)->assertOk()->assertJsonPath('data.title', 'Huge id');
    expect(readAs(bearerOf($ada), '/api/memories/9223372036854775808')->assertNotFound()->getContent())->toBe($missing->getContent());
});

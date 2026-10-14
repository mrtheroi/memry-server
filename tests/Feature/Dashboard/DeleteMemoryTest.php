<?php

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the author deletes their own memory: 204, the row is gone and the detail is a 404', function () {
    $ada = User::factory()->create();
    $memory = remember($ada, 'Mine', 'content');
    $headers = bearerOf($ada);

    deleteAs($headers, "/api/memories/{$memory->id}")->assertNoContent();

    $this->assertDatabaseMissing('observations', ['id' => $memory->id]);
    readAs($headers, "/api/memories/{$memory->id}")->assertNotFound();
});

function teamWithMember(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = contextFor($owner)->teamId;
    pinMemberAccess();
    TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $member->id, 'role' => Role::Member]);

    return [$owner, $member, $team, ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken]];
}

test('a member cannot delete a memory another member wrote: 403 with a message and the row stays', function () {
    [$owner, , $team, $memberHeaders] = teamWithMember();
    $memory = teamMemory($team, $owner, 'Owner decision');

    deleteAs($memberHeaders, "/api/memories/{$memory->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'You can only delete your own memories.');

    $this->assertDatabaseHas('observations', ['id' => $memory->id]);
});

test('an owner or an admin deletes a member project memory', function (Role $role) {
    [$owner, $member, $team] = teamWithMember();
    $manager = $role === Role::Owner ? $owner : User::factory()->create();
    $role === Role::Owner || TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $manager->id, 'role' => $role]);
    $memory = teamMemory($team, $member, 'Member decision');
    $headers = ['Authorization' => 'Bearer '.$manager->createTeamToken('cli', $team)->plainTextToken];

    deleteAs($headers, "/api/memories/{$memory->id}")->assertNoContent();

    $this->assertDatabaseMissing('observations', ['id' => $memory->id]);
})->with([Role::Owner, Role::Admin]);

test('a personal memory of another member is a 404 even for the owner, and it stays', function () {
    [$owner, $member] = teamWithMember();
    $private = personalMemory($member, 'Member private');
    $missing = deleteAs(bearerOf($owner), '/api/memories/999999')->assertNotFound();

    $response = deleteAs(bearerOf($owner), "/api/memories/{$private->id}")->assertNotFound();

    expect($response->getContent())->toBe($missing->getContent());
    $this->assertDatabaseHas('observations', ['id' => $private->id]);
});

test('a memory of another team is a 404 and stays', function () {
    $ada = User::factory()->create();
    $stranger = User::factory()->create();
    $foreign = remember($stranger, 'Foreign', 'content');

    deleteAs(bearerOf($ada), "/api/memories/{$foreign->id}")->assertNotFound();

    $this->assertDatabaseHas('observations', ['id' => $foreign->id]);
});

test('an ungranted project is a 404 and stays, a granted one is deletable by its author', function () {
    [$owner, $member, $team] = teamWithMember();
    $ungranted = teamMemory($team, $member, 'Ungranted', 'ungranted');
    $granted = teamMemory($team, $member, 'Granted', 'granted');
    pinMemberAccess([DB::table('projects')->where('name', 'granted')->value('id')]);
    $headers = ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken];

    deleteAs($headers, "/api/memories/{$ungranted->id}")->assertNotFound();
    deleteAs($headers, "/api/memories/{$granted->id}")->assertNoContent();

    $this->assertDatabaseHas('observations', ['id' => $ungranted->id]);
    $this->assertDatabaseMissing('observations', ['id' => $granted->id]);
});

test('a missing id and an out-of-range id answer the same 404', function () {
    $ada = User::factory()->create();
    $headers = bearerOf($ada);

    $missing = deleteAs($headers, '/api/memories/999999')->assertNotFound();
    $huge = deleteAs($headers, '/api/memories/99999999999999999999')->assertNotFound();

    expect($huge->getContent())->toBe($missing->getContent());
});

test('it answers 401 without credentials', function () {
    $this->deleteJson('/api/memories/1')->assertUnauthorized();
});

test('it works under a dashboard session', function () {
    $ada = User::factory()->create();
    $memory = remember($ada, 'Mine', 'content');

    sessionOf($ada)->deleteJson("/api/memories/{$memory->id}")->assertNoContent();

    $this->assertDatabaseMissing('observations', ['id' => $memory->id]);
});

<?php

use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the project list answers 401 without credentials', function () {
    $this->getJson('/api/projects')->assertUnauthorized();
});

test('the project list has the team projects with their visible memory counts', function () {
    $ada = User::factory()->create();
    $stranger = User::factory()->create();
    remember($ada, 'One', 'content', 'alpha');
    remember($ada, 'Two', 'content', 'alpha');
    remember($ada, 'Three', 'content', 'beta');
    remember($stranger, 'Foreign', 'content', 'gamma');

    $response = $this->withHeaders(bearerOf($ada))->getJson('/api/projects')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('memories_count', 'name')->all())->toBe(['alpha' => 2, 'beta' => 1])
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'memories_count', 'last_activity_at']);
});

test('project counts exclude other members personal memories but keep the callers own', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = contextFor($owner)->teamId;
    TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $member->id, 'role' => Role::Member]);
    remember($owner, 'Shared', 'content', 'alpha');
    personalMemory($owner, 'Owner private', 'alpha');
    (new EloquentMemoryRepository)->save(new Observation(
        $member->id, 's', 'decision', 'Member private', 'c', 'alpha', 'personal', null, teamId: $team,
    ));
    personalMemory($owner, 'Owner private two', 'alpha');
    pinMemberAccess();
    $memberHeaders = ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken];

    $counts = fn (array $headers) => collect(readAs($headers, '/api/projects')->assertOk()->json('data'))
        ->pluck('memories_count', 'name')->all();

    // Owner: shared + two own private. Member: shared + own private; never the owner's.
    expect($counts(bearerOf($owner)))->toBe(['alpha' => 3])
        ->and($counts($memberHeaders))->toBe(['alpha' => 2]);
});

test('projects are sorted by last activity, most recent first, and a restricted member sees only granted projects', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = contextFor($owner)->teamId;
    TeamUserRecord::factory()->create(['team_id' => $team, 'user_id' => $member->id, 'role' => Role::Member]);
    $old = teamMemory($team, $owner, 'Old', 'old-project');
    teamMemory($team, $owner, 'New', 'new-project');
    teamMemory($team, $owner, 'Hidden', 'hidden-project');
    DB::table('observations')->where('id', $old->id)->update(['updated_at' => now()->subDays(3)]);
    $granted = DB::table('projects')->whereIn('name', ['old-project', 'new-project'])->pluck('id')->all();
    pinMemberAccess($granted);
    $memberHeaders = ['Authorization' => 'Bearer '.$member->createTeamToken('cli', $team)->plainTextToken];

    $names = fn (array $headers) => array_column(readAs($headers, '/api/projects')->assertOk()->json('data'), 'name');

    expect($names(bearerOf($owner)))->toBe(['hidden-project', 'new-project', 'old-project'])
        ->and($names($memberHeaders))->toBe(['new-project', 'old-project']);
});

test('the dashboard reads allow 120 requests a minute per user and throttle the next one', function () {
    $ada = User::factory()->create();
    $headers = bearerOf($ada);

    foreach (range(1, 120) as $attempt) {
        readAs($headers, '/api/projects')->assertOk();
    }

    readAs($headers, '/api/projects')->assertStatus(429);
});

test('the project list works under a dashboard session too', function () {
    $ada = User::factory()->create();
    remember($ada, 'One', 'content', 'alpha');

    sessionOf($ada)->getJson('/api/projects')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'alpha')
        ->assertJsonPath('data.0.memories_count', 1);
});

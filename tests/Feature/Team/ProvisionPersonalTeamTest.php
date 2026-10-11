<?php

use App\Models\User;
use App\Team\Application\ProvisionPersonalTeam;
use App\Team\Domain\PersonalTeamProvisioningFailed;
use App\Team\Domain\TeamSlugs;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it provisions a personal team with its owner membership', function () {
    $user = User::factory()->create();

    $teamId = app(ProvisionPersonalTeam::class)->forUser($user->id);

    $team = TeamRecord::sole();
    expect($team->id)->toBe($teamId)
        ->and($team->owner_id)->toBe($user->id)
        ->and($team->personal_team)->toBeTrue()
        ->and($team->plan)->toBe('free')
        ->and($team->seats)->toBe(1);
    $membership = TeamUserRecord::sole();
    expect($membership->team_id)->toBe($teamId)
        ->and($membership->user_id)->toBe($user->id)
        ->and($membership->role->value)->toBe('owner');
});

test('it is idempotent and returns the same team id', function () {
    $user = User::factory()->create();
    $provision = app(ProvisionPersonalTeam::class);

    $first = $provision->forUser($user->id);
    $second = $provision->forUser($user->id);

    expect($second)->toBe($first)
        ->and(TeamRecord::count())->toBe(1)
        ->and(TeamUserRecord::count())->toBe(1);
});

function fakeSlugs(string ...$slugs): void
{
    app()->instance(TeamSlugs::class, new class($slugs) implements TeamSlugs
    {
        public function __construct(private array $queue) {}

        public function next(): string
        {
            return array_shift($this->queue) ?? throw new LogicException('Slugs exhausted.');
        }
    });
}

test('it retries with a new slug when the slug is already taken', function () {
    TeamRecord::factory()->create(['slug' => 'taken']);
    $user = User::factory()->create();
    fakeSlugs('taken', 'fresh');

    $teamId = app(ProvisionPersonalTeam::class)->forUser($user->id);

    expect(TeamRecord::findOrFail($teamId)->slug)->toBe('fresh')
        ->and(TeamUserRecord::where('team_id', $teamId)->where('user_id', $user->id)->exists())->toBeTrue();
});

test('it gives up with a domain exception after five slug collisions', function () {
    TeamRecord::factory()->create(['slug' => 'taken']);
    $user = User::factory()->create();
    fakeSlugs(...array_fill(0, 5, 'taken'), ...['never-used']);

    expect(fn () => app(ProvisionPersonalTeam::class)->forUser($user->id))
        ->toThrow(PersonalTeamProvisioningFailed::class);
});

test('it heals a personal team that has no owner membership', function () {
    $user = User::factory()->create();
    $team = TeamRecord::factory()->create(['owner_id' => $user->id, 'personal_team' => true]);
    TeamUserRecord::query()->delete();

    $teamId = app(ProvisionPersonalTeam::class)->forUser($user->id);

    expect($teamId)->toBe($team->id)
        ->and(TeamRecord::count())->toBe(1)
        ->and(TeamUserRecord::sole()->user_id)->toBe($user->id);
});

test('it generates opaque 16 hex char slugs unrelated to the user identity', function () {
    $user = User::factory()->create(['email' => 'jane.doe@example.com', 'name' => 'Jane Doe']);

    $teamId = app(ProvisionPersonalTeam::class)->forUser($user->id);

    $slug = TeamRecord::findOrFail($teamId)->slug;
    expect($slug)->toMatch('/\A[0-9a-f]{16}\z/')
        ->and($slug)->not->toContain('jane')->not->toContain('doe')->not->toContain('example');
});

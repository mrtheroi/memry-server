<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\SaveMemory;
use App\Memory\Application\SaveObservation;
use App\Memory\Domain\Observation;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function saveViaTeamToken(User $user, int $teamId, string $content): void
{
    $user->withAccessToken($user->createTeamToken('mcp', $teamId)->accessToken);

    MemoryServer::actingAs($user)->tool(SaveMemory::class, [
        'session_id' => 's', 'type' => 'decision', 'title' => 'T', 'content' => $content,
        'project' => 'dbmcp', 'topic_key' => 'architecture/auth',
    ])->assertHasNoErrors();
}

test('the same topic_key saved through two teams creates a team-local row each and never moves a row', function () {
    $user = User::factory()->create();
    $teamA = TeamRecord::where('owner_id', $user->id)->value('id');
    $teamB = TeamRecord::factory()->create(['owner_id' => $user->id])->id;

    saveViaTeamToken($user, $teamA, 'in A');
    $rowA = DB::table('observations')->where('team_id', $teamA)->sole();
    saveViaTeamToken($user, $teamB, 'in B');

    expect(DB::table('observations')->count())->toBe(2)
        ->and(DB::table('observations')->where('id', $rowA->id)->first())->toEqual($rowA)
        ->and(DB::table('observations')->where('team_id', $teamB)->value('content'))->toBe('in B');
});

test('the save is bound to the context team even if the observation names another', function () {
    $user = User::factory()->create();
    $teamA = TeamRecord::where('owner_id', $user->id)->value('id');
    $teamB = TeamRecord::factory()->create(['owner_id' => $user->id])->id;
    $context = AccessContext::forTeam($teamA, $user->id, Role::Owner, ProjectAccess::all());

    app(SaveObservation::class)(new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null, teamId: $teamB), $context);

    expect(DB::table('observations')->sole()->team_id)->toBe($teamA);
});

test('updating a row by id never touches another team', function () {
    $user = User::factory()->create();
    $teamA = TeamRecord::where('owner_id', $user->id)->value('id');
    $teamB = TeamRecord::factory()->create(['owner_id' => $user->id])->id;
    $row = fn (int $team) => new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', 'k', teamId: $team);
    $saved = (new EloquentMemoryRepository)->save($row($teamA));

    expect(fn () => (new EloquentMemoryRepository)->save($row($teamB)->withId($saved->id)))->toThrow(ModelNotFoundException::class);
    expect(DB::table('observations')->sole()->team_id)->toBe($teamA);
});

test('a denied context never adopts a team row with the same topic_key', function () {
    $user = User::factory()->create();
    $teamId = TeamRecord::where('owner_id', $user->id)->value('id');
    $observation = fn (?int $team) => new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', 'k', teamId: $team);
    $teamRow = (new EloquentMemoryRepository)->save($observation($teamId));

    app(SaveObservation::class)($observation(null), AccessContext::denied($user->id));

    expect(DB::table('observations')->count())->toBe(2)
        ->and(DB::table('observations')->where('id', $teamRow->id)->value('team_id'))->toBe($teamId);
});

test('the repository hydrates the persisted team id on save and find', function () {
    $user = User::factory()->create();
    $teamId = TeamRecord::where('owner_id', $user->id)->value('id');
    $repository = new EloquentMemoryRepository;

    $saved = $repository->save(new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null, teamId: $teamId));
    $legacy = $repository->save(new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null));

    expect($saved->teamId)->toBe($teamId)
        ->and($repository->find(contextFor($user), $saved->id)->teamId)->toBe($teamId)
        ->and($repository->find(AccessContext::system(), $legacy->id)->teamId)->toBeNull();
});

function sharedTeamWithMember(): array
{
    $owner = User::factory()->create();
    $team = TeamRecord::factory()->create(['owner_id' => $owner->id]);
    $member = User::factory()->create();
    TeamUserRecord::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member]);
    $context = fn (User $user, Role $role) => AccessContext::forTeam($team->id, $user->id, $role, ProjectAccess::all());

    return [$owner, $member, $context($owner, Role::Owner), $context($member, Role::Member)];
}

test('two members saving the same project-scope topic_key leave one row, last write wins', function () {
    [$owner, $member, $ownerContext, $memberContext] = sharedTeamWithMember();
    $note = fn (User $author, string $content) => new Observation($author->id, 's', 'decision', 'T', $content, 'dbmcp', 'project', 'k');

    app(SaveObservation::class)($note($owner, 'first'), $ownerContext);
    app(SaveObservation::class)($note($member, 'second'), $memberContext);

    $row = DB::table('observations')->sole();
    expect($row->content)->toBe('second')
        ->and($row->created_by)->toBe($owner->id)
        ->and($row->updated_by)->toBe($member->id)
        ->and($row->user_id)->toBe($owner->id);
});

test('two members saving the same personal-scope topic_key keep one row each', function () {
    [$owner, $member, $ownerContext, $memberContext] = sharedTeamWithMember();
    $note = fn (User $author, string $content) => new Observation($author->id, 's', 'decision', 'T', $content, 'dbmcp', 'personal', 'k');

    app(SaveObservation::class)($note($owner, 'mine'), $ownerContext);
    app(SaveObservation::class)($note($member, 'yours'), $memberContext);
    app(SaveObservation::class)($note($member, 'yours again'), $memberContext);

    expect(DB::table('observations')->orderBy('id')->pluck('content', 'user_id')->all())
        ->toBe([$owner->id => 'mine', $member->id => 'yours again']);
});

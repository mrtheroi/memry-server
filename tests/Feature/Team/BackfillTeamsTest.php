<?php

use App\Memory\Domain\ProjectRepository;
use App\Memory\Infrastructure\Persistence\QueryProjectRepository;
use App\Models\User;
use App\Team\Application\BackfillTeams;
use App\Team\Application\ProvisionPersonalTeam;
use App\Team\Domain\TeamSlugs;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function legacyObservation(User $user, ?string $project, array $overrides = []): int
{
    return DB::table('observations')->insertGetId($overrides + [
        'user_id' => $user->id,
        'session_id' => 's',
        'type' => 'decision',
        'title' => 't',
        'content' => 'c',
        'project' => $project,
        'scope' => 'project',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);
}

function legacyPrompt(User $user, ?string $project): int
{
    return DB::table('user_prompts')->insertGetId([
        'user_id' => $user->id,
        'session_id' => 's',
        'project' => $project,
        'content' => 'p',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);
}

test('two users with the same project name get two projects in their own teams', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $obsA = legacyObservation($a, 'alpha');
    $obsB = legacyObservation($b, 'alpha');

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    $rowA = DB::table('observations')->find($obsA);
    $rowB = DB::table('observations')->find($obsB);
    expect(DB::table('projects')->count())->toBe(2)
        ->and($rowA->team_id)->not->toBe($rowB->team_id)
        ->and($rowA->project_id)->not->toBe($rowB->project_id)
        ->and(DB::table('teams')->where('id', $rowA->team_id)->value('owner_id'))->toBe($a->id)
        ->and(DB::table('projects')->where('id', $rowA->project_id)->value('team_id'))->toBe($rowA->team_id)
        ->and($rowA->created_by)->toBe($a->id)
        ->and($rowA->updated_by)->toBe($a->id);
});

test('null and blank projects get no project row', function () {
    $user = User::factory()->create();
    $ids = [legacyObservation($user, null), legacyObservation($user, '   ')];

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    expect(DB::table('projects')->count())->toBe(0)
        ->and(DB::table('observations')->whereIn('id', $ids)->whereNotNull('team_id')->whereNull('project_id')->count())->toBe(2);
});

test('a second run changes nothing', function () {
    $user = User::factory()->create();
    legacyObservation($user, 'alpha');
    legacyPrompt($user, 'alpha');
    $user->createToken('t');
    $this->artisan('memory:backfill-teams')->assertSuccessful();
    $snapshot = fn () => [DB::table('observations')->get(), DB::table('user_prompts')->get(), DB::table('projects')->get(), DB::table('teams')->get(), DB::table('personal_access_tokens')->get()];
    $before = $snapshot();

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    expect($snapshot())->toEqual($before);
});

test('it reuses the team created at signup', function () {
    $user = User::factory()->create();
    $teamId = app(ProvisionPersonalTeam::class)->forUser($user->id);
    $id = legacyObservation($user, 'alpha');

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    expect(DB::table('teams')->count())->toBe(1)
        ->and(DB::table('observations')->find($id)->team_id)->toBe($teamId);
});

test('name variants map to one project per ProjectName rules', function () {
    $user = User::factory()->create();
    $ids = array_map(fn ($p) => legacyObservation($user, $p), ['Alpha', ' alpha ', 'ALPHA']);
    $dashed = legacyObservation($user, 'al--pha');

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    expect(DB::table('observations')->whereIn('id', $ids)->distinct()->pluck('project_id'))->toHaveCount(1)
        ->and(DB::table('projects')->orderBy('name')->pluck('name')->all())->toBe(['al-pha', 'alpha'])
        ->and(DB::table('observations')->find($dashed)->project_id)->not->toBe(DB::table('observations')->find($ids[0])->project_id);
});

function backfillSnapshot(): array
{
    $named = fn (string $table, array $columns) => DB::table($table)->leftJoin('projects', 'projects.id', '=', "$table.project_id")
        ->orderBy("$table.id")->get(array_merge(array_map(fn ($c) => "$table.$c", $columns), ['projects.name as project_name']));

    return [
        $named('observations', ['id', 'team_id', 'created_by', 'updated_by', 'user_id', 'updated_at']),
        $named('user_prompts', ['id', 'team_id']),
        DB::table('projects')->orderBy('team_id')->orderBy('name')->get(['team_id', 'name']),
    ];
}

function seedLegacy(): void
{
    $users = User::factory()->count(2)->create();
    foreach ($users as $user) {
        foreach (['alpha', 'beta', null, 'alpha'] as $project) {
            legacyObservation($user, $project);
            legacyPrompt($user, $project);
        }
    }
}

test('a multi-chunk run equals a single pass', function () {
    seedLegacy();
    $this->artisan('memory:backfill-teams', ['--chunk' => 1])->assertSuccessful();
    $chunked = backfillSnapshot();
    DB::table('observations')->update(['team_id' => null, 'project_id' => null, 'created_by' => null, 'updated_by' => null]);
    DB::table('user_prompts')->update(['team_id' => null, 'project_id' => null]);
    DB::table('projects')->delete();

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    expect(backfillSnapshot())->toEqual($chunked);
});

test('resuming after the first chunk equals an uninterrupted run', function () {
    seedLegacy();
    app(BackfillTeams::class)->run(1, 1);
    expect(DB::table('observations')->whereNull('team_id')->count())->toBe(4);
    $this->artisan('memory:backfill-teams', ['--chunk' => 3])->assertSuccessful();
    $resumed = backfillSnapshot();
    expect(DB::table('observations')->whereNull('team_id')->count())->toBe(0);

    DB::table('observations')->update(['team_id' => null, 'project_id' => null, 'created_by' => null, 'updated_by' => null]);
    DB::table('user_prompts')->update(['team_id' => null, 'project_id' => null]);
    DB::table('projects')->delete();
    $this->artisan('memory:backfill-teams')->assertSuccessful();

    expect(backfillSnapshot())->toEqual($resumed);
});

test('it never touches updated_at or user_id', function () {
    $user = User::factory()->create();
    $id = legacyObservation($user, 'alpha');

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    $row = DB::table('observations')->find($id);
    expect($row->updated_at)->toBe('2026-01-02 00:00:00')
        ->and($row->user_id)->toBe($user->id);
});

test('prompts get a team and a project', function () {
    $user = User::factory()->create();
    $id = legacyPrompt($user, 'Alpha');

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    $row = DB::table('user_prompts')->find($id);
    expect($row->team_id)->not->toBeNull()
        ->and(DB::table('projects')->where('id', $row->project_id)->value('name'))->toBe('alpha')
        ->and($row->user_id)->toBe($user->id);
});

test('a legacy token gets the personal team and still authenticates', function () {
    $user = User::factory()->create();
    $token = $user->createToken('legacy')->plainTextToken;
    $this->withToken($token)->get('/api/context?project=dbmcp')->assertOk();

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    $this->app['auth']->forgetGuards();
    expect(DB::table('personal_access_tokens')->value('team_id'))
        ->toBe(DB::table('teams')->where('owner_id', $user->id)->value('id'));
    $this->withToken($token)->get('/api/context?project=dbmcp')->assertOk();
});

test('check fails while rows lack a team and passes after a full run', function () {
    $user = User::factory()->create();
    legacyObservation($user, 'alpha');
    legacyPrompt($user, 'alpha');
    $user->createToken('legacy');

    $this->artisan('memory:backfill-teams', ['--check' => true])
        ->expectsOutputToContain('observations without team: 1')
        ->expectsOutputToContain('user_prompts without team: 1')
        ->expectsOutputToContain('tokens without team: 1')
        ->assertFailed();
    expect(DB::table('observations')->whereNull('team_id')->count())->toBe(1);

    $this->artisan('memory:backfill-teams')->assertSuccessful();

    $this->artisan('memory:backfill-teams', ['--check' => true])->assertSuccessful();
});

test('check reports a team without an owner membership', function () {
    $user = User::factory()->create();
    $this->artisan('memory:backfill-teams')->assertSuccessful();
    DB::table('team_user')->delete();

    $this->artisan('memory:backfill-teams', ['--check' => true])
        ->expectsOutputToContain('teams without owner: 1')
        ->assertFailed();
});

test('a user who signs up while the backfill runs gets a team for their rows', function () {
    $early = User::factory()->create();
    legacyObservation($early, 'alpha');

    // A signup during the first user's transaction is not in the user chunk
    // already read; the next keyset chunk (id > last) picks them up.
    app()->bind(ProvisionPersonalTeam::class, fn ($app) => new class($app->make(TeamSlugs::class)) extends ProvisionPersonalTeam
    {
        private bool $signedUp = false;

        public function forUser(int $userId): int
        {
            if (! $this->signedUp) {
                $this->signedUp = true;
                legacyObservation(User::factory()->create(), 'beta');
            }

            return parent::forUser($userId);
        }
    });

    app(BackfillTeams::class)->run(500);

    expect(DB::table('observations')->whereNull('team_id')->count())->toBe(0)
        ->and(DB::table('teams')->where('personal_team', true)->count())->toBe(2);
});

test('check reports a user without a personal team or owner membership', function () {
    $user = User::factory()->create();

    $this->artisan('memory:backfill-teams', ['--check' => true])
        ->expectsOutputToContain('users without personal team: 1')
        ->assertFailed();

    $this->artisan('memory:backfill-teams')->assertSuccessful();
    $this->artisan('memory:backfill-teams', ['--check' => true])
        ->expectsOutputToContain('users without personal team: 0')
        ->assertSuccessful();

    DB::table('team_user')->where('user_id', $user->id)->delete();
    $this->artisan('memory:backfill-teams', ['--check' => true])
        ->expectsOutputToContain('users without personal team: 1')
        ->assertFailed();
});

test('a user\'s tokens are backfilled in sub-chunks, each transaction locking the user first, and still authenticate', function () {
    $user = User::factory()->create();
    $plain = array_map(fn ($n) => $user->createToken($n)->plainTextToken, ['a', 'b', 'c']);

    $locks = 0;
    DB::listen(function ($query) use (&$locks) {
        $locks += str_contains(strtolower($query->sql), 'from "users" where "id" = ? for share') ? 1 : 0;
    });

    // maxChunks bounds user chunks: this one user is finished, 1 token per transaction.
    app(BackfillTeams::class)->run(1, 1);

    expect(DB::table('personal_access_tokens')->whereNull('team_id')->count())->toBe(0)
        ->and($locks)->toBeGreaterThanOrEqual(3);
    foreach ($plain as $token) {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->get('/api/context?project=dbmcp')->assertOk();
    }
});

test('a row written by newer code during the chunk is never overwritten', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $raced = legacyObservation($user, 'alpha');
    $filled = legacyObservation($user, 'alpha');
    $racedPrompt = legacyPrompt($user, 'alpha');
    $filledPrompt = legacyPrompt($user, 'alpha');

    app()->bind(ProjectRepository::class, fn ($app) => new class($app->make(QueryProjectRepository::class), $raced, $racedPrompt) implements ProjectRepository
    {
        private bool $raced = false;

        public function __construct(private ProjectRepository $inner, private int $obsId, private int $promptId) {}

        public function idFor(int $teamId, string $name): ?int
        {
            return $this->inner->idFor($teamId, $name);
        }

        public function getOrCreateId(int $teamId, string $name): int
        {
            if (! $this->raced) {
                $this->raced = true;
                $otherUser = DB::table('users')->orderByDesc('id')->value('id');
                $otherTeam = app(ProvisionPersonalTeam::class)->forUser($otherUser);
                $projectId = $this->inner->getOrCreateId($otherTeam, 'newer');
                $values = ['team_id' => $otherTeam, 'project_id' => $projectId];
                DB::table('observations')->where('id', $this->obsId)->update($values);
                DB::table('user_prompts')->where('id', $this->promptId)->update($values);
            }

            return $this->inner->getOrCreateId($teamId, $name);
        }
    });

    app(BackfillTeams::class)->run(500);

    $otherTeam = DB::table('teams')->where('owner_id', $other->id)->value('id');
    $userTeam = DB::table('teams')->where('owner_id', $user->id)->value('id');
    foreach (['observations' => [$raced, $filled], 'user_prompts' => [$racedPrompt, $filledPrompt]] as $table => [$racedId, $filledId]) {
        $racedRow = DB::table($table)->find($racedId);
        expect($racedRow->team_id)->toBe($otherTeam)
            ->and(DB::table('projects')->where('id', $racedRow->project_id)->value('name'))->toBe('newer')
            ->and(DB::table($table)->find($filledId)->team_id)->toBe($userTeam);
    }
    expect(DB::table('observations')->find($raced)->created_by)->toBeNull();
});

test('users are provisioned in keyset chunks and every user gets a personal team', function () {
    User::factory()->count(3)->create();
    legacyObservation(User::first(), 'alpha');
    $unbounded = 0;
    DB::listen(function ($query) use (&$unbounded) {
        // Bounded: a keyset chunk (limit) or one user by primary key (the lock).
        if (preg_match('/^select .* from "users"/i', $query->sql) && ! str_contains($query->sql, 'limit') && ! str_contains($query->sql, '"id" = ?')) {
            $unbounded++;
        }
    });

    $result = app(BackfillTeams::class)->run(1);

    expect($unbounded)->toBe(0)
        ->and($result['teams'])->toBe(3)
        ->and(DB::table('teams')->where('personal_team', true)->count())->toBe(3);
    $this->artisan('memory:backfill-teams', ['--check' => true])->assertSuccessful();
});

test('a user deleted before their team is provisioned is skipped, with their orphan tokens', function () {
    $gone = User::factory()->create();
    $goneId = $gone->id;
    $gone->delete();
    // tokenable_id has no foreign key, so a token can outlive its user: the
    // same state as an account deleted mid-run.
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class, 'tokenable_id' => $goneId, 'name' => 'memry-cli',
        'token' => hash('sha256', 'orphan'), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(BackfillTeams::class)->run(500);

    expect(DB::table('teams')->where('owner_id', $goneId)->exists())->toBeFalse();
    $this->artisan('memory:backfill-teams', ['--check' => true])->assertExitCode(0);
});

test('each user is backfilled in one transaction that locks the user row first', function () {
    $user = User::factory()->create();
    legacyObservation($user, 'alpha');
    legacyPrompt($user, 'alpha');
    $user->createToken('legacy');
    $base = DB::transactionLevel();
    $transactions = [];
    Event::listen(TransactionBeginning::class, function () use ($base, &$transactions) {
        if (DB::transactionLevel() === $base + 1) {
            $transactions[] = [];
        }
    });
    DB::listen(function ($query) use ($base, &$transactions) {
        if (DB::transactionLevel() > $base) {
            $transactions[count($transactions) - 1][] = strtolower($query->sql);
        }
    });

    app(BackfillTeams::class)->run(500);

    $touches = fn (array $statements, string $needle) => collect($statements)->search(fn ($s) => str_contains($s, $needle));
    $complete = collect($transactions)->filter(fn ($s) => $touches($s, 'update "personal_access_tokens"') !== false);
    expect($complete)->toHaveCount(1);
    $statements = $complete->first();
    $lock = $touches($statements, 'from "users"');
    expect($statements[$lock])->toContain('for share')
        ->and($lock)->toBe(0);
    foreach (['insert into teams ', 'update "personal_access_tokens"', 'update "observations"', 'update "user_prompts"'] as $write) {
        expect($touches($statements, $write))->toBeGreaterThan($lock);
    }
});

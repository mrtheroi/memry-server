<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function canonicalizeMigration(): object
{
    return require collect(glob(database_path('migrations/*_canonicalize_legacy_project_names.php')))->sole();
}

function legacyProjectId(int $teamId, string $name): int
{
    return DB::table('projects')->insertGetId(['team_id' => $teamId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
}

/** A pre-canonical row: the team and project_id are set, the raw text is whatever the old app stored. */
function rawRow(string $table, User $user, ?int $projectId, ?string $project, array $overrides = []): int
{
    $row = $overrides + [
        'user_id' => $user->id,
        'team_id' => contextFor($user)->teamId,
        'project_id' => $projectId,
        'project' => $project,
        'session_id' => 's',
        'content' => 'c',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ];

    return DB::table($table)->insertGetId($table === 'observations' ? $row + ['type' => 'note', 'title' => 't', 'scope' => 'project'] : $row);
}

dataset('tables', ['observations', 'user_prompts']);

it('rewrites the spellings of one project to its canonical name', function (string $table) {
    $user = User::factory()->create();
    $projectId = legacyProjectId(contextFor($user)->teamId, 'alpha');
    $ids = collect(['Alpha', ' alpha ', 'ALPHA'])->map(fn ($raw) => rawRow($table, $user, $projectId, $raw));

    canonicalizeMigration()->up();

    expect(DB::table($table)->whereIn('id', $ids)->pluck('project')->all())->toBe(['alpha', 'alpha', 'alpha']);
})->with('tables');

it('nulls blank project text that has no project, and leaves anything else alone', function (string $table) {
    $user = User::factory()->create();
    $blank = collect(['', '   ', "\t\n \x0B"])->map(fn ($raw) => rawRow($table, $user, null, $raw));
    $kept = rawRow($table, $user, null, ' Orphan ');

    canonicalizeMigration()->up();

    expect(DB::table($table)->whereIn('id', $blank)->whereNotNull('project')->count())->toBe(0)
        ->and(DB::table($table)->find($kept)->project)->toBe(' Orphan ');
})->with('tables');

it('is idempotent: a second run changes nothing', function () {
    $user = User::factory()->create();
    $projectId = legacyProjectId(contextFor($user)->teamId, 'alpha');
    foreach (['observations', 'user_prompts'] as $table) {
        rawRow($table, $user, $projectId, 'ALPHA');
        rawRow($table, $user, null, '  ');
    }
    $snapshot = fn () => [DB::table('observations')->orderBy('id')->get(), DB::table('user_prompts')->orderBy('id')->get()];
    canonicalizeMigration()->up();
    $afterFirst = $snapshot();

    canonicalizeMigration()->up();

    expect($snapshot())->toEqual($afterFirst);
});

it('writes nothing but project', function (string $table) {
    $user = User::factory()->create();
    $projectId = legacyProjectId(contextFor($user)->teamId, 'alpha');
    $id = rawRow($table, $user, $projectId, ' Alpha ', ['content' => 'keep me']);
    $before = (array) DB::table($table)->find($id);

    canonicalizeMigration()->up();

    $after = (array) DB::table($table)->find($id);
    expect($after['project'])->toBe('alpha')
        ->and(array_diff_assoc($after, $before))->toBe(['project' => 'alpha'])
        ->and($after['updated_at'])->toBe('2026-01-02 00:00:00')
        ->and($after['content'])->toBe('keep me');
})->with('tables');

it('batches across id ranges', function () {
    $user = User::factory()->create();
    $projectId = legacyProjectId(contextFor($user)->teamId, 'alpha');
    $first = rawRow('observations', $user, $projectId, 'Alpha');
    DB::statement("SELECT setval(pg_get_serial_sequence('observations', 'id'), ?)", [$first + 2500]);
    $far = rawRow('observations', $user, $projectId, 'ALPHA');

    canonicalizeMigration()->up();

    expect(DB::table('observations')->whereIn('id', [$first, $far])->pluck('project')->all())->toBe(['alpha', 'alpha']);
});

it('makes the stats and the dashboard filter see one project', function () {
    $user = User::factory()->create();
    $projectId = legacyProjectId(contextFor($user)->teamId, 'alpha');
    foreach (['Alpha', ' alpha ', 'ALPHA'] as $raw) {
        rawRow('observations', $user, $projectId, $raw, ['created_at' => now()->toDateTimeString()]);
    }
    $stats = fn () => readAs(bearerOf($user), '/api/stats')->assertOk()->json('data');
    $listed = fn () => readAs(bearerOf($user), '/api/memories?project=alpha')->assertOk()->json('data');
    expect($stats()['totals']['projects'])->toBe(3)
        ->and($listed())->toHaveCount(0);

    canonicalizeMigration()->up();

    expect($stats()['totals']['projects'])->toBe(1)
        ->and($stats()['by_project'])->toHaveCount(1)
        ->and($listed())->toHaveCount(3);
});

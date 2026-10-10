<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\SaveMemory;
use App\Mcp\Tools\SavePrompt;
use App\Models\User;
use App\Team\Infrastructure\Persistence\ProjectRecord;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TEAM_FKS = [
    ['observations', 'team_id'], ['observations', 'project_id'],
    ['observations', 'created_by'], ['observations', 'updated_by'],
    ['user_prompts', 'team_id'], ['user_prompts', 'project_id'],
    ['personal_access_tokens', 'team_id'],
];

const TEAM_INDEX_MIGRATIONS = [
    'add_team_indexes_to_observations_table',
    'add_team_indexes_to_user_prompts_and_tokens',
];

function teamIndexMigration(string $name): object
{
    return require collect(glob(database_path("migrations/*_{$name}.php")))->sole();
}

function observationRow(int $userId, array $extra = []): array
{
    return $extra + [
        'user_id' => $userId, 'session_id' => 's', 'type' => 'note',
        'title' => 't', 'content' => 'c', 'created_at' => now(), 'updated_at' => now(),
    ];
}

test('the existing write path leaves the new team columns NULL', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)->tool(SaveMemory::class, [
        'session_id' => 's', 'type' => 'decision', 'title' => 'T', 'content' => 'C', 'project' => 'p',
    ])->assertOk();
    MemoryServer::actingAs($user)->tool(SavePrompt::class, [
        'session_id' => 's', 'project' => 'p', 'content' => 'hello',
    ])->assertOk();
    $token = $user->createToken('t')->accessToken;

    $observation = DB::table('observations')->sole();
    $prompt = DB::table('user_prompts')->sole();
    expect([$observation->team_id, $observation->project_id, $observation->created_by, $observation->updated_by])
        ->each->toBeNull()
        ->and([$prompt->team_id, $prompt->project_id])->each->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('id', $token->id)->value('team_id'))->toBeNull();
});

test('deleting the creator keeps the observation with created_by NULL', function () {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    DB::table('observations')->insert(observationRow($owner->id, ['created_by' => $creator->id, 'updated_by' => $creator->id]));

    $creator->delete();

    $row = DB::table('observations')->sole();
    expect($row->created_by)->toBeNull()->and($row->updated_by)->toBeNull();
});

test('deleting a project keeps its observations and prompts with project_id NULL', function () {
    $user = User::factory()->create();
    $project = ProjectRecord::factory()->create();
    DB::table('observations')->insert(observationRow($user->id, ['project_id' => $project->id]));
    DB::table('user_prompts')->insert(['user_id' => $user->id, 'session_id' => 's', 'content' => 'c', 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);

    $project->delete();

    expect(DB::table('observations')->sole()->project_id)->toBeNull()
        ->and(DB::table('user_prompts')->sole()->project_id)->toBeNull();
});

test('deleting a team cascades its observations, prompts and tokens', function () {
    $user = User::factory()->create();
    $team = TeamRecord::factory()->create();
    $token = $user->createToken('t')->accessToken;
    DB::table('personal_access_tokens')->where('id', $token->id)->update(['team_id' => $team->id]);
    DB::table('observations')->insert(observationRow($user->id, ['team_id' => $team->id]));
    DB::table('user_prompts')->insert(['user_id' => $user->id, 'session_id' => 's', 'content' => 'c', 'team_id' => $team->id, 'created_at' => now(), 'updated_at' => now()]);

    $team->delete();

    expect(DB::table('observations')->count())->toBe(0)
        ->and(DB::table('user_prompts')->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('every new foreign key is added NOT VALID', function () {
    foreach (TEAM_FKS as [$table, $column]) {
        $validated = DB::selectOne(
            'select convalidated from pg_constraint where conname = ?',
            ["{$table}_{$column}_foreign"],
        );
        expect($validated?->convalidated)->toBeFalse("{$table}.{$column}");
    }
});

test('the index migrations run outside a transaction and build concurrently', function () {
    foreach (TEAM_INDEX_MIGRATIONS as $name) {
        $migration = teamIndexMigration($name);
        $sql = collect(DB::pretend(fn () => $migration->up()))->pluck('query')->implode(';');

        expect($migration->withinTransaction)->toBeFalse()
            ->and(substr_count($sql, 'CREATE INDEX CONCURRENTLY'))->toBe(substr_count($sql, 'CREATE INDEX'))
            ->and($sql)->toContain('DROP INDEX CONCURRENTLY IF EXISTS');
    }
});

test('the team indexes exist and are valid', function () {
    $expected = [
        'observations_team_project_updated_idx', 'observations_team_topic_key_idx',
        'observations_project_id_idx', 'observations_created_by_idx', 'observations_updated_by_idx',
        'user_prompts_team_project_idx', 'user_prompts_project_id_idx', 'personal_access_tokens_team_id_idx',
    ];

    $valid = DB::table('pg_index')->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
        ->whereIn('pg_class.relname', $expected)->where('indisvalid', true)->pluck('relname')->all();

    expect($valid)->toEqualCanonicalizing($expected);
});

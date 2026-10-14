<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const NOT_NULL_TABLES = [
    'observations' => 'set_observations_team_id_not_null',
    'user_prompts' => 'set_user_prompts_team_id_not_null',
];

function notNullMigration(string $table): object
{
    return require collect(glob(database_path('migrations/*_'.NOT_NULL_TABLES[$table].'.php')))->sole();
}

function insertWithoutTeam(string $table, int $userId): void
{
    $row = ['user_id' => $userId, 'session_id' => 's', 'content' => 'c', 'created_at' => now(), 'updated_at' => now()];

    DB::table($table)->insert($table === 'observations' ? $row + ['type' => 'note', 'title' => 't'] : $row);
}

dataset('contracted tables', array_keys(NOT_NULL_TABLES));

it('rejects a row without team_id once migrated', function (string $table) {
    $user = User::factory()->create();

    expect(fn () => insertWithoutTeam($table, $user->id))->toThrow(QueryException::class, 'not-null constraint');
})->with('contracted tables');

it('refuses to run, naming the backfill, while a row has no team_id', function (string $table) {
    $user = User::factory()->create();
    $migration = notNullMigration($table);
    $migration->down();
    insertWithoutTeam($table, $user->id);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, "Run memory:backfill-teams first: {$table} has 1 rows without team_id");
})->with('contracted tables');

it('leaves no check constraint behind, applied or refused', function (string $table) {
    $user = User::factory()->create();
    $migration = notNullMigration($table);
    $migration->down();
    insertWithoutTeam($table, $user->id);
    try {
        $migration->up();
    } catch (RuntimeException) {
    }
    DB::table($table)->delete();
    $migration->up();

    expect(DB::table('pg_constraint')->where('conname', "{$table}_team_id_not_null")->exists())->toBeFalse();
})->with('contracted tables');

it('restores nullability on rollback', function (string $table) {
    $user = User::factory()->create();
    notNullMigration($table)->down();

    insertWithoutTeam($table, $user->id);

    expect(DB::table($table)->whereNull('team_id')->count())->toBe(1);
})->with('contracted tables');

it('runs outside a wrapping transaction so each VALIDATE is its own statement', function (string $table) {
    expect(notNullMigration($table)->withinTransaction)->toBeFalse();
})->with('contracted tables');

it('keeps personal_access_tokens.team_id nullable, since orphan and legacy tokens may have none', function () {
    $user = User::factory()->create();
    $token = $user->createToken('legacy')->accessToken;

    expect(DB::table('personal_access_tokens')->where('id', $token->id)->value('team_id'))->toBeNull();
});

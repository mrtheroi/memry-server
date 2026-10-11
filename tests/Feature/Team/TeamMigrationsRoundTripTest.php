<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the team migrations and their dependents roll back and re-apply cleanly', function () {
    $tables = ['teams', 'team_user', 'projects', 'project_grants'];

    // By file, not by --step: later migrations must not shift what this rolls
    // back. Add a migration here when a later one depends on these tables.
    $migrations = collect([
        'change_projects_name_to_text',
        'add_team_indexes_to_user_prompts_and_tokens',
        'add_team_indexes_to_observations_table',
        'add_team_id_to_personal_access_tokens_table',
        'add_team_columns_to_user_prompts_table',
        'add_team_columns_to_observations_table',
        'create_project_grants_table',
        'create_projects_table',
        'create_team_user_table',
        'create_teams_table',
    ])->map(fn ($name) => collect(glob(database_path("migrations/*_{$name}.php")))->sole())
        ->map(fn ($file) => 'database/migrations/'.basename($file))
        ->all();

    // DROP INDEX CONCURRENTLY cannot run inside RefreshDatabase's wrapping transaction.
    DB::commit();

    Artisan::call('migrate:rollback', ['--path' => $migrations]);
    expect(collect($tables)->filter(fn ($t) => Schema::hasTable($t)))->toBeEmpty();

    Artisan::call('migrate');
    expect(collect($tables)->filter(fn ($t) => Schema::hasTable($t)))->toHaveCount(4);
});

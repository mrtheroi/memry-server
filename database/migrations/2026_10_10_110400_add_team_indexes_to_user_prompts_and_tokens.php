<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.
    public $withinTransaction = false;

    private const INDEXES = [
        'user_prompts_team_project_idx' => ['user_prompts', '(team_id, project)'],
        'user_prompts_project_id_idx' => ['user_prompts', '(project_id)'],
        'personal_access_tokens_team_id_idx' => ['personal_access_tokens', '(team_id)'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            // A failed concurrent build leaves an INVALID index behind; drop it so a retry rebuilds.
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON {$table} {$columns}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};

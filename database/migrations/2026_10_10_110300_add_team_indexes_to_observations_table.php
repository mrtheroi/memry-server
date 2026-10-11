<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.
    public $withinTransaction = false;

    private const INDEXES = [
        'observations_team_project_updated_idx' => '(team_id, project, updated_at)',
        'observations_team_topic_key_idx' => '(team_id, project, scope, topic_key) WHERE topic_key IS NOT NULL',
        'observations_project_id_idx' => '(project_id)',
        'observations_created_by_idx' => '(created_by)',
        'observations_updated_by_idx' => '(updated_by)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            // A failed concurrent build leaves an INVALID index behind; drop it so a retry rebuilds.
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON observations {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};

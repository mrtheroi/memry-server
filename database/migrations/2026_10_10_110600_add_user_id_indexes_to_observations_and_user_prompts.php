<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.
    public $withinTransaction = false;

    // Plain (non-partial) on purpose: besides the per-user backfill scans they
    // serve the account-deletion cascade for good, and Postgres does not index
    // foreign key columns by itself.
    private const INDEXES = [
        'observations_user_id_id_idx' => ['observations', '(user_id, id)'],
        'user_prompts_user_id_id_idx' => ['user_prompts', '(user_id, id)'],
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

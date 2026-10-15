<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.
    public $withinTransaction = false;

    private const INDEX = 'observations_team_created_idx';

    public function up(): void
    {
        // A failed concurrent build leaves an INVALID index behind; drop it so a retry rebuilds.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON observations (team_id, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }
};

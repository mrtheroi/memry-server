<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deploy gate, no DDL: team-scoped reads hide rows without a team, so refuse to go live
     * until memory:backfill-teams has filled every row.
     */
    public function up(): void
    {
        $missing = DB::table('observations')->whereNull('team_id')->count()
            + DB::table('user_prompts')->whereNull('team_id')->count();

        if ($missing > 0) {
            throw new RuntimeException("Run memory:backfill-teams first: {$missing} rows without team_id");
        }
    }

    public function down(): void {}
};

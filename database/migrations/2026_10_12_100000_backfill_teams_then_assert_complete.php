<?php

use App\Team\Application\BackfillTeams;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * BackfillTeams commits per user; a wrapping migration transaction would turn that into one
     * long transaction holding every lock until the end and losing all progress on failure.
     */
    public $withinTransaction = false;

    /**
     * Team-scoped reads hide rows without a team, so this migration fills every row (idempotent,
     * chunked, per-user locks) and then asserts none is left.
     *
     * It deliberately calls application code: a self-hosted install upgrading from the released
     * image cannot run memory:backfill-teams before migrate, and with AUTO_MIGRATE the app would
     * not start. BackfillTeams must therefore stay backward compatible with the schema as it is
     * at this migration, whatever migrations come after it.
     */
    public function up(): void
    {
        app(BackfillTeams::class)->run(500);

        $missing = DB::table('observations')->whereNull('team_id')->count()
            + DB::table('user_prompts')->whereNull('team_id')->count();

        // Only reachable if something concurrent wrote legacy rows while the backfill ran.
        if ($missing > 0) {
            throw new RuntimeException("Run memory:backfill-teams first: {$missing} rows without team_id");
        }
    }

    public function down(): void {}
};

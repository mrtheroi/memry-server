<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// CONTRACT phase of expand -> backfill -> contract. Ship it in a release of its own, only after
// `php artisan memory:backfill-teams --check` reports 0 everywhere; it never fills a team itself.
return new class extends Migration
{
    // Keeps ADD CONSTRAINT, VALIDATE and SET NOT NULL as separate short statements instead of one
    // transaction holding the ACCESS EXCLUSIVE lock across the validation scan.
    public $withinTransaction = false;

    private const TABLE = 'user_prompts';

    private const CHECK = 'user_prompts_team_id_not_null';

    public function up(): void
    {
        $missing = DB::table(self::TABLE)->whereNull('team_id')->count();

        if ($missing > 0) {
            throw new RuntimeException('Run memory:backfill-teams first: '.self::TABLE." has {$missing} rows without team_id");
        }

        $this->withLockTimeout(function () {
            // A retry after a failed VALIDATE finds the constraint still there.
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CHECK.' CHECK (team_id IS NOT NULL) NOT VALID');
        });

        // SHARE UPDATE EXCLUSIVE: writes keep flowing while existing rows are scanned.
        DB::statement('ALTER TABLE '.self::TABLE.' VALIDATE CONSTRAINT '.self::CHECK);

        $this->withLockTimeout(function () {
            // PG12+ trusts the validated CHECK and skips the full-table scan.
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN team_id SET NOT NULL');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CHECK);
        });
    }

    public function down(): void
    {
        $this->withLockTimeout(fn () => DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN team_id DROP NOT NULL'));
    }

    private function withLockTimeout(callable $statements): void
    {
        DB::statement("SET lock_timeout = '5s'");

        try {
            $statements();
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};

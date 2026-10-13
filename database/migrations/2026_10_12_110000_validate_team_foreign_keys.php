<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Keeps each VALIDATE in its own short statement instead of one long
    // transaction holding locks across all of them.
    public $withinTransaction = false;

    // Added NOT VALID by the team column migrations. The backfill and the
    // dual-write guarantee every row satisfies them, including the composite
    // keys (a row's project belongs to the row's team).
    private const FOREIGN_KEYS = [
        'observations' => [
            'observations_team_id_foreign',
            'observations_project_id_foreign',
            'observations_created_by_foreign',
            'observations_updated_by_foreign',
            'observations_project_team_foreign',
        ],
        'user_prompts' => [
            'user_prompts_team_id_foreign',
            'user_prompts_project_id_foreign',
            'user_prompts_project_team_foreign',
        ],
        'personal_access_tokens' => [
            'personal_access_tokens_team_id_foreign',
        ],
    ];

    public function up(): void
    {
        // VALIDATE CONSTRAINT takes SHARE UPDATE EXCLUSIVE: writes keep flowing.
        // The constraint is already enforced for new rows; this scans old ones.
        foreach (self::FOREIGN_KEYS as $table => $constraints) {
            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: a validated constraint cannot be turned back
        // into NOT VALID, and leaving it validated is harmless. The column
        // migrations drop the constraints when they roll back.
    }
};

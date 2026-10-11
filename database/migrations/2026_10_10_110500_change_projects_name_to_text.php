<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Normalization can expand characters (e.g. 'İ' lowercases to two code
    // points), so a 255-character legacy name can normalize to more than 255.
    // varchar -> text is binary-compatible: no table rewrite, and the canonical
    // CHECK and unique(team_id, name) are kept.
    public function up(): void
    {
        DB::statement('ALTER TABLE projects ALTER COLUMN name TYPE text');
    }

    public function down(): void
    {
        // Intentionally a no-op: keeping text is safe, while narrowing to
        // varchar(255) would fail (or lose data) once a longer normalized name
        // exists. Rolling back create_projects_table drops the table anyway.
    }
};

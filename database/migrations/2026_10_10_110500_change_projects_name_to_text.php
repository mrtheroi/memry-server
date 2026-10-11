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
        DB::statement('ALTER TABLE projects ALTER COLUMN name TYPE varchar(255)');
    }
};

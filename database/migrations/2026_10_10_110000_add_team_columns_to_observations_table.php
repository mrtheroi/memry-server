<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fail fast instead of queueing behind (and blocking) production writes.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        // Nullable, no default: metadata-only, no table rewrite.
        Schema::table('observations', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        // NOT VALID skips the full-table scan; validated in a later task.
        foreach ([
            'team_id' => ['teams', 'CASCADE'],
            'project_id' => ['projects', 'SET NULL'],
            'created_by' => ['users', 'SET NULL'],
            'updated_by' => ['users', 'SET NULL'],
        ] as $column => [$table, $onDelete]) {
            DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES {$table}(id) ON DELETE {$onDelete} NOT VALID");
        }
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('observations', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['team_id', 'project_id', 'created_by', 'updated_by']);
        });
    }
};

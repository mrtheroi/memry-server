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

        // A row's project must belong to the row's team. The target needs a
        // unique key on (id, team_id); the table is empty in production, so
        // the index build is instant. The composite FK (MATCH SIMPLE) skips
        // rows with a NULL project_id or team_id; the single project_id FK
        // above keeps handling project deletion (SET NULL).
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_id_team_id_unique UNIQUE (id, team_id)');
        DB::statement('ALTER TABLE observations ADD CONSTRAINT observations_project_team_foreign FOREIGN KEY (project_id, team_id) REFERENCES projects (id, team_id) NOT VALID');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE observations DROP CONSTRAINT observations_project_team_foreign');

        Schema::table('observations', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['team_id', 'project_id', 'created_by', 'updated_by']);
        });

        // user_prompts' composite FK is already gone: its migration rolls back first.
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_id_team_id_unique');
    }
};

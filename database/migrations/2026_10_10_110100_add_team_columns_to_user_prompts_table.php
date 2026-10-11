<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('user_prompts', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
        });

        DB::statement('ALTER TABLE user_prompts ADD CONSTRAINT user_prompts_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE user_prompts ADD CONSTRAINT user_prompts_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL NOT VALID');
        DB::statement('ALTER TABLE user_prompts ADD CONSTRAINT user_prompts_project_team_foreign FOREIGN KEY (project_id, team_id) REFERENCES projects (id, team_id) NOT VALID');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE user_prompts DROP CONSTRAINT user_prompts_project_team_foreign');

        Schema::table('user_prompts', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropForeign(['project_id']);
            $table->dropColumn(['team_id', 'project_id']);
        });
    }
};

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

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable();
        });

        DB::statement('ALTER TABLE personal_access_tokens ADD CONSTRAINT personal_access_tokens_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE NOT VALID');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};

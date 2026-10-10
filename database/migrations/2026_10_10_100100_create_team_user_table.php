<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE team_user ADD CONSTRAINT team_user_role_check CHECK (role IN ('owner', 'admin', 'member'))");
        DB::statement("CREATE UNIQUE INDEX team_user_team_id_owner_unique ON team_user (team_id) WHERE role = 'owner'");

        // The owner row must be exactly teams.owner_id. The generated column is
        // NULL for non-owners, so MATCH SIMPLE skips them. The key is checked per
        // statement; an ownership transfer defers it inside its own transaction
        // (SET CONSTRAINTS team_user_owner_foreign DEFERRED). There is no reverse
        // key: a team without an owner row is allowed here and fails closed in
        // the application.
        DB::statement("ALTER TABLE team_user ADD COLUMN owner_user_id bigint GENERATED ALWAYS AS (CASE WHEN role = 'owner' THEN user_id END) STORED");
        DB::statement('ALTER TABLE team_user ADD CONSTRAINT team_user_team_id_owner_user_id_unique UNIQUE (team_id, owner_user_id)');
        DB::statement('ALTER TABLE team_user ADD CONSTRAINT team_user_owner_foreign FOREIGN KEY (team_id, owner_user_id) REFERENCES teams (id, owner_id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};

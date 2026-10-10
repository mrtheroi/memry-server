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

        // The owner row must be exactly teams.owner_id, and every team must have
        // one. The generated column is NULL for non-owners, so MATCH SIMPLE skips
        // them. Both foreign keys are deferred so that team + owner row can be
        // inserted in either order and ownership transferred in one transaction.
        DB::statement("ALTER TABLE team_user ADD COLUMN owner_user_id bigint GENERATED ALWAYS AS (CASE WHEN role = 'owner' THEN user_id END) STORED");
        DB::statement('ALTER TABLE team_user ADD CONSTRAINT team_user_team_id_owner_user_id_unique UNIQUE (team_id, owner_user_id)');
        DB::statement('ALTER TABLE team_user ADD CONSTRAINT team_user_owner_foreign FOREIGN KEY (team_id, owner_user_id) REFERENCES teams (id, owner_id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_owner_membership_foreign FOREIGN KEY (id, owner_id) REFERENCES team_user (team_id, owner_user_id) DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        // The reverse key points at team_user, so it must go first.
        DB::statement('ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_owner_membership_foreign');
        Schema::dropIfExists('team_user');
    }
};

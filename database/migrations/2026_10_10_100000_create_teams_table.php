<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('slug', 32)->unique();
            $table->boolean('personal_team')->default(false);
            $table->string('plan')->default('free');
            $table->unsignedInteger('seats')->default(1);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX teams_owner_id_personal_unique ON teams (owner_id) WHERE personal_team');
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};

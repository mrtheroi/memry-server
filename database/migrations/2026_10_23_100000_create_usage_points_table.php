<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 10);
            $table->string('model', 200);
            $table->string('token_type', 20)->nullable();
            $table->string('query_source', 200)->nullable();
            $table->string('session_id', 200)->nullable();
            // Unix nanoseconds: they fit a signed bigint until the year 2262.
            $table->bigInteger('start_unix_nano');
            $table->bigInteger('end_unix_nano');
            $table->decimal('value', 20, 6);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'user_id', 'end_unix_nano'], 'usage_points_read_idx');
        });

        // One row per series and window: a retried export hits this and is skipped by ON CONFLICT DO NOTHING.
        // NULLS NOT DISTINCT (PostgreSQL 15+) makes the nullable columns compare equal, so uniqueness holds for them too.
        DB::statement('CREATE UNIQUE INDEX usage_points_series_window_unique ON usage_points '
            .'(user_id, metric, model, token_type, query_source, session_id, start_unix_nano, end_unix_nano) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_points');
    }
};

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
            // sha256 of the metric name plus the complete data point attribute set: the series identity.
            $table->char('series_hash', 64);
            $table->string('model', 200);
            $table->string('token_type', 20)->default('');
            $table->string('query_source', 200)->default('');
            $table->string('session_id', 200)->default('');
            // Unix nanoseconds: they fit a signed bigint until the year 2262.
            $table->bigInteger('start_unix_nano');
            $table->bigInteger('end_unix_nano');
            $table->decimal('value', 38, 6);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'user_id', 'end_unix_nano'], 'usage_points_read_idx');
        });

        // One row per team, user, series and window: a retried export hits this and is skipped by ON CONFLICT DO NOTHING.
        // series_hash is NOT NULL, so no NULL-handling index feature is needed (works on PostgreSQL 14).
        DB::statement('CREATE UNIQUE INDEX usage_points_series_window_unique ON usage_points '
            .'(team_id, user_id, metric, series_hash, start_unix_nano, end_unix_nano)');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_points');
    }
};

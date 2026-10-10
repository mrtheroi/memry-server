<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        // Mirrors App\Memory\Domain\ProjectName::normalize(): trimmed (PHP trim
        // set minus NUL, which Postgres text cannot hold), lowercased, non-empty,
        // with runs of '-' and of '_' collapsed. lower() may differ from
        // mb_strtolower() for some non-ASCII locales; the app always normalizes
        // first, so the CHECK only guards against writes that bypass it.
        DB::statement(<<<'SQL'
            ALTER TABLE projects ADD CONSTRAINT projects_name_canonical_check CHECK (
                name <> ''
                AND name = lower(btrim(name, E' \t\n\r\x0B'))
                AND name !~ '-{2,}'
                AND name !~ '_{2,}'
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

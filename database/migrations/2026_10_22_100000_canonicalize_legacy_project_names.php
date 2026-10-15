<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Legacy rows kept the raw project spelling the client sent ("Alpha", " alpha ") while BackfillTeams
// mapped every spelling to one project_id. This makes the raw column canonical (the projects.name of
// its project_id) so every reader that compares or groups by the text agrees with project_id.
// Only `project` is written: updated_at, user_id and content are untouched, and nothing in the schema
// (the search_vector column is generated from title and content) depends on it.
return new class extends Migration
{
    // One short transaction per batch instead of a single one holding row locks across the tables.
    public $withinTransaction = false;

    private const BATCH = 1000;

    public function up(): void
    {
        foreach (['observations', 'user_prompts'] as $table) {
            $this->inBatches($table, function (int $from, int $to) use ($table) {
                DB::update(
                    "UPDATE {$table} t SET project = p.name FROM projects p
                     WHERE t.project_id = p.id AND t.project IS DISTINCT FROM p.name AND t.id BETWEEN ? AND ?",
                    [$from, $to],
                );

                // Mirrors ProjectName::normalize(): the PHP trim set (minus NUL, which text cannot hold)
                // leaves nothing. A non-blank text without a project_id is not ours to guess; it stays.
                DB::update(
                    "UPDATE {$table} SET project = NULL
                     WHERE project_id IS NULL AND project IS NOT NULL
                       AND btrim(project, E' \\t\\n\\r\\x0B') = '' AND id BETWEEN ? AND ?",
                    [$from, $to],
                );
            });
        }
    }

    // Not reversible by design: the original spellings are not kept.
    public function down(): void {}

    private function inBatches(string $table, callable $update): void
    {
        $max = (int) DB::table($table)->max('id');

        for ($from = 1; $from <= $max; $from += self::BATCH) {
            DB::statement("SET lock_timeout = '5s'");

            try {
                $update($from, $from + self::BATCH - 1);
            } finally {
                DB::statement('RESET lock_timeout');
            }
        }
    }
};

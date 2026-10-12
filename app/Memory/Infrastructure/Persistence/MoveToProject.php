<?php

namespace App\Memory\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * Moves the rows of a project to another and keeps project_id consistent per team:
 * the destination project row is created for each team that has rows to move, rows
 * without a team keep a NULL project_id. The source project row is kept.
 */
final class MoveToProject
{
    /**
     * @param  'observations'|'user_prompts'  $table
     */
    public function __invoke(string $table, string $from, string $to, ?int $userId): int
    {
        $filter = 'project = ?'.($userId ? ' AND user_id = ?' : '');
        $bindings = $userId ? [$from, $userId] : [$from];
        $now = now();

        DB::statement(
            "INSERT INTO projects (team_id, name, created_at, updated_at)
             SELECT DISTINCT team_id, ?::text, ?::timestamp, ?::timestamp FROM {$table} WHERE {$filter} AND team_id IS NOT NULL
             ON CONFLICT (team_id, name) DO NOTHING",
            [$to, $now, $now, ...$bindings],
        );

        return DB::update(
            "UPDATE {$table} SET project = ?,
             project_id = (SELECT p.id FROM projects p WHERE p.team_id = {$table}.team_id AND p.name = ?)
             WHERE {$filter}",
            [$to, $to, ...$bindings],
        );
    }
}

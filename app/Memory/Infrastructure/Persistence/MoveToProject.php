<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Team\Domain\AccessContext;
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
    public function __invoke(AccessContext $context, string $table, string $from, string $to): int
    {
        if ($context->isDenied()) {
            return 0;
        }

        $filter = 'project = ?'.($context->isSystem() ? '' : ' AND team_id = ?');
        $bindings = $context->isSystem() ? [$from] : [$from, $context->teamId];
        $now = now();

        // One statement: project creation and the row move share a snapshot, so a project
        // created concurrently cannot slip between them. A data-modifying CTE exposes its
        // inserted rows only through RETURNING, hence the COALESCE with the existing project.
        return DB::update(
            "WITH src AS (
                 SELECT DISTINCT team_id FROM {$table} WHERE {$filter} AND team_id IS NOT NULL
             ), ins AS (
                 INSERT INTO projects (team_id, name, created_at, updated_at)
                 SELECT team_id, ?::text, ?::timestamp, ?::timestamp FROM src
                 ON CONFLICT (team_id, name) DO NOTHING
                 RETURNING id, team_id
             )
             UPDATE {$table} AS t SET project = ?::text,
             project_id = COALESCE(
                 (SELECT ins.id FROM ins WHERE ins.team_id = t.team_id),
                 (SELECT p.id FROM projects p WHERE p.team_id = t.team_id AND p.name = ?::text)
             )
             WHERE {$filter}",
            [...$bindings, $to, $now, $now, $to, $to, ...$bindings],
        );
    }
}

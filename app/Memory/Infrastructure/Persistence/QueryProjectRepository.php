<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\ProjectRepository;
use App\Memory\Domain\ProjectSummary;
use App\Team\Domain\AccessContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class QueryProjectRepository implements ProjectRepository
{
    public function idFor(int $teamId, string $name): ?int
    {
        return DB::table('projects')->where('team_id', $teamId)->where('name', $name)->value('id');
    }

    public function getOrCreateId(int $teamId, string $name): int
    {
        $now = now();
        DB::statement(
            'INSERT INTO projects (team_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (team_id, name) DO NOTHING',
            [$teamId, $name, $now, $now],
        );

        return $this->idFor($teamId, $name);
    }

    public function visibleTo(AccessContext $context): array
    {
        if ($context->isDenied()) {
            return [];
        }

        return DB::table('projects')
            ->leftJoin('observations', function ($join) use ($context) {
                $join->on('observations.project_id', '=', 'projects.id')
                    ->where(fn ($visible) => $visible->where('observations.scope', '<>', 'personal')
                        ->orWhere('observations.user_id', $context->userId));
            })
            ->when(! $context->isSystem(), fn ($query) => $query->where('projects.team_id', $context->teamId))
            ->when(! $context->projects->isAll(), fn ($query) => $query->whereIn('projects.id', $context->projects->ids()))
            ->groupBy('projects.id', 'projects.name')
            ->orderByRaw('max(observations.updated_at) DESC NULLS LAST')
            ->orderBy('projects.name')
            ->get(['projects.id', 'projects.name', DB::raw('count(observations.id) as memories_count'), DB::raw('max(observations.updated_at) as last_activity_at')])
            ->map(fn ($row) => new ProjectSummary(
                (int) $row->id,
                $row->name,
                (int) $row->memories_count,
                $row->last_activity_at === null ? null : new DateTimeImmutable($row->last_activity_at),
            ))
            ->all();
    }
}

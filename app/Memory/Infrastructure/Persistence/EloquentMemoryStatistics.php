<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\MemoryActivity;
use App\Memory\Domain\MemoryStatistics;
use App\Team\Domain\AccessContext;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

class EloquentMemoryStatistics implements MemoryStatistics
{
    private const TOP_PROJECTS = 10;

    public function __construct(private EloquentMemoryRepository $memories = new EloquentMemoryRepository) {}

    public function activity(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): MemoryActivity
    {
        if ($context->isDenied()) {
            return new MemoryActivity(['memories' => 0, 'projects' => 0, 'session_summaries' => 0], [], [], []);
        }

        return new MemoryActivity(
            $this->totals($context),
            $this->daily($context, $from, $to),
            $this->byType($context, $from, $to),
            $this->byProject($context, $from, $to),
        );
    }

    /**
     * @return array{memories: int, projects: int, session_summaries: int}
     */
    private function totals(AccessContext $context): array
    {
        $row = $this->memories->scopeToReadable(ObservationRecord::query(), $context)
            ->selectRaw('count(*) as memories, count(distinct project) as projects, count(*) filter (where type = \'session_summary\') as session_summaries')
            ->toBase()
            ->first();

        return ['memories' => (int) $row->memories, 'projects' => (int) $row->projects, 'session_summaries' => (int) $row->session_summaries];
    }

    /**
     * @return array<string, array{memories: int, session_summaries: int}>
     */
    private function daily(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inRange($context, $from, $to)
            ->selectRaw('created_at::date as day, count(*) as memories, count(*) filter (where type = \'session_summary\') as session_summaries')
            ->groupByRaw('created_at::date')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->day => ['memories' => (int) $row->memories, 'session_summaries' => (int) $row->session_summaries]])
            ->all();
    }

    /**
     * @return list<array{type: string, count: int}>
     */
    private function byType(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inRange($context, $from, $to)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->orderByRaw('count(*) DESC')
            ->orderBy('type')
            ->get()
            ->map(fn ($row) => ['type' => $row->type, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * @return list<array{project: string, count: int}>
     */
    private function byProject(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inRange($context, $from, $to)
            ->whereNotNull('project')
            ->selectRaw('project, count(*) as count')
            ->groupBy('project')
            ->orderByRaw('count(*) DESC')
            ->orderBy('project')
            ->limit(self::TOP_PROJECTS)
            ->get()
            ->map(fn ($row) => ['project' => $row->project, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * The readable memories created from the start of $from through the end of $to (UTC days).
     */
    private function inRange(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return $this->memories->scopeToReadable(ObservationRecord::query(), $context)
            ->where('created_at', '>=', $from->format('Y-m-d 00:00:00'))
            ->where('created_at', '<', $to->modify('+1 day')->format('Y-m-d 00:00:00'));
    }
}

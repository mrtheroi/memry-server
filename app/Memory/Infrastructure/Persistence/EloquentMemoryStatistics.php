<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\MemoryActivity;
use App\Memory\Domain\MemoryStatistics;
use App\Team\Domain\AccessContext;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

class EloquentMemoryStatistics implements MemoryStatistics
{
    public function __construct(private EloquentMemoryRepository $memories = new EloquentMemoryRepository) {}

    public function activity(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): MemoryActivity
    {
        if ($context->isDenied()) {
            return new MemoryActivity(['memories' => 0, 'projects' => 0, 'session_summaries' => 0], [], [], []);
        }

        return new MemoryActivity(
            ['memories' => 0, 'projects' => 0, 'session_summaries' => 0],
            $this->daily($context, $from, $to),
            $this->byType($context, $from, $to),
            [],
        );
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
     * The readable memories created from the start of $from through the end of $to (UTC days).
     */
    private function inRange(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return $this->memories->scopeToReadable(ObservationRecord::query(), $context)
            ->where('created_at', '>=', $from->format('Y-m-d 00:00:00'))
            ->where('created_at', '<', $to->modify('+1 day')->format('Y-m-d 00:00:00'));
    }
}

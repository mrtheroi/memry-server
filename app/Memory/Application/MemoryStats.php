<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryActivity;
use App\Memory\Domain\MemoryStatistics;
use App\Team\Domain\AccessContext;
use DateInterval;
use Carbon\CarbonImmutable;

class MemoryStats
{
    public function __construct(private MemoryStatistics $statistics) {}

    /**
     * The activity of the last $days UTC days, today included, with every day present.
     *
     * @return array{from: string, to: string, activity: MemoryActivity, daily: list<array{date: string, memories: int, session_summaries: int}>}
     */
    public function __invoke(AccessContext $context, int $days): array
    {
        $to = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->sub(new DateInterval('P'.($days - 1).'D'));
        $activity = $this->statistics->activity($context, $from, $to);

        $daily = [];
        for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
            $key = $day->format('Y-m-d');
            $daily[] = ['date' => $key] + ($activity->daily[$key] ?? ['memories' => 0, 'session_summaries' => 0]);
        }

        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'activity' => $activity, 'daily' => $daily];
    }
}

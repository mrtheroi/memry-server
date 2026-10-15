<?php

namespace App\Memory\Domain;

/**
 * What a caller can read, aggregated for the dashboard overview. Days are UTC calendar days
 * ("Y-m-d"); only days with activity are present, the caller fills the gaps.
 */
final readonly class MemoryActivity
{
    /**
     * @param  array{memories: int, projects: int, session_summaries: int}  $totals  all time
     * @param  array<string, array{memories: int, session_summaries: int}>  $daily  keyed by day
     * @param  list<array{type: string, count: int}>  $byType  within the range
     * @param  list<array{project: string, count: int}>  $byProject  within the range
     */
    public function __construct(
        public array $totals,
        public array $daily,
        public array $byType,
        public array $byProject,
    ) {}
}

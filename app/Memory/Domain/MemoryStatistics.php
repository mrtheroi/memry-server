<?php

namespace App\Memory\Domain;

use App\Team\Domain\AccessContext;
use DateTimeImmutable;

/**
 * Read side of the memory activity, for the dashboard. Counts only the memories the caller can read.
 */
interface MemoryStatistics
{
    /**
     * Totals over everything readable; daily, type and project breakdowns over the UTC days from
     * $from through $to inclusive. A denied context reads nothing.
     */
    public function activity(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): MemoryActivity;
}

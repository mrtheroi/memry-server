<?php

namespace App\Usage\Domain;

use DateTimeImmutable;

interface UsageRepository
{
    /**
     * Store the points for a user and team, skipping any point already stored for the same series
     * and window so a retried export never double counts. Returns how many were newly stored.
     *
     * @param  list<UsagePoint>  $points
     */
    public function record(int $teamId, int $userId, array $points): int;

    /**
     * The user's usage on the team from the start of $from through the end of $to (UTC days),
     * each point counted on the UTC day of its end time.
     */
    public function summary(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): UsageSummary;
}

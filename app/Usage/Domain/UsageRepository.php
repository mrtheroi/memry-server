<?php

namespace App\Usage\Domain;

interface UsageRepository
{
    /**
     * Store the points for a user and team, skipping any point already stored for the same series
     * and window so a retried export never double counts. Returns how many were newly stored.
     *
     * @param  list<UsagePoint>  $points
     */
    public function record(int $teamId, int $userId, array $points): int;
}

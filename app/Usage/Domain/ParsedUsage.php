<?php

namespace App\Usage\Domain;

final readonly class ParsedUsage
{
    /**
     * @param  list<UsagePoint>  $points
     * @param  int  $rejectedNonDelta  data points of interest skipped for not being delta temporality
     * @param  int  $rejectedInvalid  delta data points skipped for a missing or invalid value
     */
    public function __construct(public array $points, public int $rejectedNonDelta, public int $rejectedInvalid = 0) {}
}

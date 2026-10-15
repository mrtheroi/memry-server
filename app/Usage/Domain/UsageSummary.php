<?php

namespace App\Usage\Domain;

/**
 * A user's usage on one team, aggregated over a range of UTC days. Only days with usage are
 * present in $daily; the caller fills the gaps.
 */
final readonly class UsageSummary
{
    /**
     * @param  array<string, array{tokens: array{input: int, output: int, cacheRead: int, cacheCreation: int}, cost_usd: float}>  $daily  keyed by "Y-m-d"
     * @param  list<array{model: string, tokens: int, cost_usd: float}>  $byModel
     * @param  array{tokens: int, cost_usd: float}  $totals
     */
    public function __construct(
        public array $daily,
        public array $byModel,
        public array $totals,
    ) {}
}

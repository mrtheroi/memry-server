<?php

namespace App\Usage\Domain;

/**
 * A user's usage on one team, aggregated over a range of UTC days. Only days with usage are
 * present in $daily; the caller fills the gaps.
 */
final readonly class UsageSummary
{
    /**
     * @param  array<string, array{tokens: array{input: string, output: string, cacheRead: string, cacheCreation: string}, cost_usd: string}>  $daily  keyed by "Y-m-d"
     * @param  list<array{model: ?string, tokens: string, cost_usd: string}>  $byModel
     * @param  array{tokens: string, cost_usd: string}  $totals
     */
    public function __construct(
        public array $daily,
        public array $byModel,
        public array $totals,
    ) {}
}

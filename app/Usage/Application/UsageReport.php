<?php

namespace App\Usage\Application;

use App\Team\Domain\AccessContext;
use App\Usage\Domain\UsageRepository;
use App\Usage\Domain\UsageSummary;
use Carbon\CarbonImmutable;
use DateInterval;

final class UsageReport
{
    private const NO_TOKENS = ['input' => '0', 'output' => '0', 'cacheRead' => '0', 'cacheCreation' => '0'];

    public function __construct(private UsageRepository $usage) {}

    /**
     * The caller's own usage on the acting team over the last $days UTC days, today included, with every day present.
     *
     * @return array{days: int, from: string, to: string, daily: list<array{date: string, tokens: array{input: string, output: string, cacheRead: string, cacheCreation: string}, cost_usd: string}>, by_model: list<array{model: ?string, tokens: string, cost_usd: string}>, totals: array{tokens: string, cost_usd: string}}
     */
    public function __invoke(AccessContext $context, int $days): array
    {
        $to = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->sub(new DateInterval('P'.($days - 1).'D'));
        // Like the other dashboard reads, a caller with no team reads zeros; the system context is nobody's own usage.
        $summary = $context->isDenied() || $context->isSystem()
            ? new UsageSummary([], [], ['tokens' => '0', 'cost_usd' => '0.000000'])
            : $this->usage->summary($context->teamId, $context->userId, $from, $to);

        $daily = [];
        for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
            $key = $day->format('Y-m-d');
            $daily[] = ['date' => $key] + ($summary->daily[$key] ?? ['tokens' => self::NO_TOKENS, 'cost_usd' => '0.000000']);
        }

        return [
            'days' => $days,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'daily' => $daily,
            'by_model' => $summary->byModel,
            'totals' => $summary->totals,
        ];
    }
}

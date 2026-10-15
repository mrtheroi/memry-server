<?php

namespace App\Usage\Infrastructure\Persistence;

use App\Usage\Domain\UsageOverflow;
use App\Usage\Domain\UsagePoint;
use App\Usage\Domain\UsageRepository;
use App\Usage\Domain\UsageSummary;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class QueryUsageRepository implements UsageRepository
{
    private const NANOS_PER_DAY = 86_400_000_000_000;

    private const TOKEN_TYPES = ['input', 'output', 'cacheRead', 'cacheCreation'];

    private const TOTAL_TOKENS = "coalesce(sum(value) filter (where metric = 'tokens'), 0) as tokens";

    // Rounded in SQL (exact numeric) so no float error creeps into the 6 decimals.
    private const TOTAL_COST = "round(coalesce(sum(value) filter (where metric = 'cost'), 0), 6) as cost_usd";

    public function record(int $teamId, int $userId, array $points): int
    {
        if ($points === []) {
            return 0;
        }

        // An absent optional attribute is stored as '' (see the migration); the domain keeps null.
        $now = now();

        // One INSERT ... ON CONFLICT DO NOTHING for the whole batch (at most 5000 rows x 12 columns, under
        // PostgreSQL's 65535 bind-parameter limit); the unique index drops the points already stored.
        return DB::table('usage_points')->insertOrIgnore(array_map(fn (UsagePoint $point) => [
            'team_id' => $teamId,
            'user_id' => $userId,
            'metric' => $point->metric->value,
            'series_hash' => $point->seriesHash,
            'model' => $point->model,
            'token_type' => $point->tokenType ?? '',
            'query_source' => $point->querySource ?? '',
            'session_id' => $point->sessionId ?? '',
            'start_unix_nano' => $point->startUnixNano,
            'end_unix_nano' => $point->endUnixNano,
            'value' => $point->value,
            'created_at' => $now,
        ], $points));
    }

    public function summary(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): UsageSummary
    {
        return new UsageSummary(
            $this->daily($teamId, $userId, $from, $to),
            $this->byModel($teamId, $userId, $from, $to),
            $this->totals($teamId, $userId, $from, $to),
        );
    }

    /**
     * Integer division of the end time buckets each point on its UTC day (days since the epoch).
     * Only the four known token types are pivoted.
     *
     * @return array<string, array{tokens: array{input: int, output: int, cacheRead: int, cacheCreation: int}, cost_usd: float}>
     */
    private function daily(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $tokens = fn (string $type) => "coalesce(sum(value) filter (where metric = 'tokens' and token_type = '{$type}'), 0) as \"{$type}\"";

        return $this->inRange($teamId, $userId, $from, $to)
            ->selectRaw('end_unix_nano / '.self::NANOS_PER_DAY.' as day, '
                .implode(', ', array_map($tokens, self::TOKEN_TYPES)).', '
                .self::TOTAL_COST)
            ->groupByRaw('end_unix_nano / '.self::NANOS_PER_DAY)
            ->get()
            ->mapWithKeys(fn ($row) => [gmdate('Y-m-d', (int) $row->day * 86400) => [
                'tokens' => array_combine(self::TOKEN_TYPES, array_map(fn (string $type) => $this->tokens($row->{$type}), self::TOKEN_TYPES)),
                'cost_usd' => (float) $row->cost_usd,
            ]])
            ->all();
    }

    /**
     * Every token type counts here, including any the daily pivot leaves out.
     *
     * @return list<array{model: string, tokens: int, cost_usd: float}>
     */
    private function byModel(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inRange($teamId, $userId, $from, $to)
            ->selectRaw('model, '.self::TOTAL_TOKENS.', '.self::TOTAL_COST)
            ->groupBy('model')
            ->orderByDesc('tokens')
            ->orderBy('model')
            ->get()
            ->map(fn ($row) => ['model' => $row->model, 'tokens' => $this->tokens($row->tokens), 'cost_usd' => (float) $row->cost_usd])
            ->all();
    }

    /**
     * @return array{tokens: int, cost_usd: float}
     */
    private function totals(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = $this->inRange($teamId, $userId, $from, $to)->selectRaw(self::TOTAL_TOKENS.', '.self::TOTAL_COST)->first();

        return ['tokens' => $this->tokens($row->tokens), 'cost_usd' => (float) $row->cost_usd];
    }

    /**
     * The user's own points on the team that end from the start of $from through the end of $to (UTC days);
     * the (team_id, user_id, end_unix_nano) index serves this range.
     */
    private function inRange(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return DB::table('usage_points')
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->where('end_unix_nano', '>=', $this->startOfDayNanos($from))
            ->where('end_unix_nano', '<', $this->startOfDayNanos($to->modify('+1 day')));
    }

    /**
     * A token sum as an int, checked: the numeric sum is compared as a decimal string, so a value past int64
     * throws instead of saturating at PHP_INT_MAX. Sums are never negative (the ingest rejects negative values).
     */
    private function tokens(int|string $sum): int
    {
        $digits = ltrim(explode('.', (string) $sum)[0], '0') ?: '0';
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw UsageOverflow::tokens((string) $sum);
        }

        return (int) $digits;
    }

    private function startOfDayNanos(DateTimeImmutable $day): int
    {
        return intdiv($day->getTimestamp(), 86400) * self::NANOS_PER_DAY;
    }
}

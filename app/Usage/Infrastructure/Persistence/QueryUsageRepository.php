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

    // GROUPING(day, model) per grouping set: grouped by day only, by model only, or neither (the total).
    private const DAY_ROW = 1;

    private const MODEL_ROW = 2;

    private const TOTAL_ROW = 3;

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

    /**
     * One statement, one snapshot: GROUPING SETS computes the per-day rows, the per-model rows and the
     * total row together, so daily, by_model and totals can never disagree (separate queries could each see
     * a different set of rows while the ingest writes). GROUPING(day, model) tells the rows apart: it sets a
     * bit for each expression the row is NOT grouped by.
     */
    public function summary(int $teamId, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): UsageSummary
    {
        $day = 'end_unix_nano / '.self::NANOS_PER_DAY;
        $tokens = fn (string $type) => "coalesce(sum(value) filter (where metric = 'tokens' and token_type = '{$type}'), 0) as \"{$type}\"";

        $rows = $this->inRange($teamId, $userId, $from, $to)
            ->selectRaw("grouping({$day}, model) as grouping_set, {$day} as day, model, "
                .implode(', ', array_map($tokens, self::TOKEN_TYPES)).', '
                ."coalesce(sum(value) filter (where metric = 'tokens'), 0) as tokens, "
                // Rounded in SQL (exact numeric) so no float error creeps into the 6 decimals.
                ."round(coalesce(sum(value) filter (where metric = 'cost'), 0), 6) as cost_usd")
            ->groupByRaw("grouping sets (({$day}), (model), ())")
            ->orderByDesc('tokens')
            ->orderBy('model')
            ->get()
            ->groupBy('grouping_set');

        $total = $rows[self::TOTAL_ROW]->sole();

        return new UsageSummary(
            // Only the four known token types are pivoted per day; tokens and by_model count every type.
            ($rows[self::DAY_ROW] ?? collect())->mapWithKeys(fn ($row) => [gmdate('Y-m-d', (int) $row->day * 86400) => [
                'tokens' => array_combine(self::TOKEN_TYPES, array_map(fn (string $type) => $this->tokens($row->{$type}), self::TOKEN_TYPES)),
                'cost_usd' => (float) $row->cost_usd,
            ]])->all(),
            ($rows[self::MODEL_ROW] ?? collect())
                ->map(fn ($row) => ['model' => $row->model, 'tokens' => $this->tokens($row->tokens), 'cost_usd' => (float) $row->cost_usd])
                ->values()
                ->all(),
            ['tokens' => $this->tokens($total->tokens), 'cost_usd' => (float) $total->cost_usd],
        );
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

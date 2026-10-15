<?php

namespace App\Usage\Infrastructure\Persistence;

use App\Usage\Domain\UsagePoint;
use App\Usage\Domain\UsageRepository;
use Illuminate\Support\Facades\DB;

final class QueryUsageRepository implements UsageRepository
{
    public function record(int $teamId, int $userId, array $points): int
    {
        if ($points === []) {
            return 0;
        }

        $now = now();

        // One INSERT ... ON CONFLICT DO NOTHING for the whole batch (at most 5000 rows x 11 columns, under
        // PostgreSQL's 65535 bind-parameter limit); the unique index drops the points already stored.
        return DB::table('usage_points')->insertOrIgnore(array_map(fn (UsagePoint $point) => [
            'team_id' => $teamId,
            'user_id' => $userId,
            'metric' => $point->metric->value,
            'model' => $point->model,
            'token_type' => $point->tokenType,
            'query_source' => $point->querySource,
            'session_id' => $point->sessionId,
            'start_unix_nano' => $point->startUnixNano,
            'end_unix_nano' => $point->endUnixNano,
            'value' => $point->value,
            'created_at' => $now,
        ], $points));
    }
}

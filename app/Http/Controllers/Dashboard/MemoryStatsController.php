<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Memory\Application\MemoryStats;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryStatsController extends Controller
{
    private const DEFAULT_DAYS = 30;

    public function __invoke(Request $request, MemoryStats $memoryStats, AccessContext $context): JsonResponse
    {
        $input = $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:365']]);
        $days = (int) ($input['days'] ?? self::DEFAULT_DAYS);
        $stats = $memoryStats($context, $days);

        return response()->json(['data' => [
            'range' => ['days' => $days, 'from' => $stats['from'], 'to' => $stats['to']],
            'totals' => $stats['activity']->totals,
            'daily' => $stats['daily'],
            'by_type' => $stats['activity']->byType,
            'by_project' => $stats['activity']->byProject,
        ]]);
    }
}

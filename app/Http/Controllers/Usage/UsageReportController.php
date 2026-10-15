<?php

namespace App\Http\Controllers\Usage;

use App\Http\Controllers\Controller;
use App\Team\Domain\AccessContext;
use App\Usage\Application\UsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageReportController extends Controller
{
    private const DEFAULT_DAYS = 30;

    public function __invoke(Request $request, UsageReport $usageReport, AccessContext $context): JsonResponse
    {
        // Only an omitted `days` defaults; a present but empty value is a 422, not a silent 30.
        $input = $request->validate(['days' => ['sometimes', 'required', 'integer', 'min:1', 'max:90']]);

        return response()->json($usageReport($context, (int) ($input['days'] ?? self::DEFAULT_DAYS)));
    }
}

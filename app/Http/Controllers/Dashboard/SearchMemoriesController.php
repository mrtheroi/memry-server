<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Memory\Application\SearchMemories;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchMemoriesController extends Controller
{
    private const DEFAULT_LIMIT = 20;

    public function __invoke(Request $request, SearchMemories $searchMemories, AccessContext $context): JsonResponse
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'project' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $memories = $searchMemories($context, trim($input['q']), (int) ($input['limit'] ?? self::DEFAULT_LIMIT), $input['project'] ?? null);

        return response()->json(['data' => array_map(MemoryPayload::summary(...), $memories)]);
    }
}

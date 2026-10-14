<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Memory\Application\ListMemories;
use App\Memory\Domain\InvalidCursor;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ListMemoriesController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    public function __invoke(Request $request, ListMemories $listMemories, AccessContext $context): JsonResponse
    {
        $input = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'cursor' => ['nullable', 'string', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $page = $listMemories($context, $input['project'] ?? null, $input['type'] ?? null, $input['cursor'] ?? null, (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE));
        } catch (InvalidCursor $e) {
            throw ValidationException::withMessages(['cursor' => $e->getMessage()]);
        }

        return response()->json([
            'data' => array_map(MemoryPayload::summary(...), $page->items),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->previousCursor, 'per_page' => $page->perPage],
        ]);
    }
}

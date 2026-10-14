<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Memory\Application\DeleteMemory;
use App\Memory\Domain\MemoryDeletionDenied;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteMemoryController extends Controller
{
    public function __invoke(string $id, DeleteMemory $deleteMemory, AccessContext $context): Response|JsonResponse
    {
        // Same guard as the detail: ids past the bigint range cannot exist and answer the same 404.
        try {
            $deleted = filter_var($id, FILTER_VALIDATE_INT) !== false && $deleteMemory($context, (int) $id);
        } catch (MemoryDeletionDenied $denied) {
            return response()->json(['message' => $denied->getMessage()], 403);
        }

        abort_unless($deleted, 404);

        return response()->noContent();
    }
}

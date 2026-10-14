<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Memory\Application\ShowMemory;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;

class ShowMemoryController extends Controller
{
    public function __invoke(string $id, ShowMemory $showMemory, AccessContext $context): JsonResponse
    {
        // Ids past the bigint range cannot exist; answering here keeps them out of the query and the same 404.
        $memory = strlen($id) > 18 ? null : $showMemory($context, (int) $id);

        abort_if($memory === null, 404);

        return response()->json(['data' => MemoryPayload::detail($memory)]);
    }
}

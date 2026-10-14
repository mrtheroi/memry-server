<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Memory\Application\ListProjects;
use App\Memory\Domain\ProjectSummary;
use App\Team\Domain\AccessContext;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;

class ListProjectsController extends Controller
{
    public function __invoke(ListProjects $listProjects, AccessContext $context): JsonResponse
    {
        return response()->json(['data' => array_map(fn (ProjectSummary $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'memories_count' => $project->memoriesCount,
            'last_activity_at' => $project->lastActivityAt?->format(DateTimeInterface::ATOM),
        ], $listProjects($context))]);
    }
}

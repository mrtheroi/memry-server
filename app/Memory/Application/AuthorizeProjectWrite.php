<?php

namespace App\Memory\Application;

use App\Memory\Domain\ProjectRepository;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessDenied;

/**
 * Write gate shared by the save paths. A denied context is the legacy user without a team,
 * which still writes the legacy columns only; it never reaches a team's projects.
 */
final class AuthorizeProjectWrite
{
    public function __construct(private ProjectRepository $projects) {}

    public function __invoke(AccessContext $context, ?string $project): void
    {
        if ($context->isSystem()) {
            throw AccessDenied::systemContext();
        }

        if ($context->isDenied() || $context->projects->isAll()) {
            return;
        }

        $projectId = $project === null ? null : $this->projects->idFor($context->teamId, $project);

        if ($projectId === null || ! in_array($projectId, $context->projects->ids(), true)) {
            throw AccessDenied::forProject($project);
        }
    }
}

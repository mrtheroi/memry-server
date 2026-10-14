<?php

namespace App\Memory\Application;

use App\Memory\Domain\ProjectRepository;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessDenied;

/**
 * Write gate shared by the save paths. Every row belongs to a team, so a denied context (no
 * resolvable team) and the system context are refused; a team context needs access to the project.
 */
final class AuthorizeProjectWrite
{
    public function __construct(private ProjectRepository $projects) {}

    public function __invoke(AccessContext $context, ?string $project): void
    {
        if ($context->isSystem()) {
            throw AccessDenied::systemContext();
        }

        if ($context->isDenied()) {
            throw AccessDenied::noTeam();
        }

        if ($context->projects->isAll()) {
            return;
        }

        $projectId = $project === null ? null : $this->projects->idFor($context->teamId, $project);

        if ($projectId === null || ! in_array($projectId, $context->projects->ids(), true)) {
            throw AccessDenied::forProject($project);
        }
    }
}

<?php

namespace App\Memory\Application;

use App\Memory\Domain\ProjectRepository;
use App\Memory\Domain\ProjectSummary;
use App\Team\Domain\AccessContext;

class ListProjects
{
    public function __construct(private ProjectRepository $projects) {}

    /**
     * @return list<ProjectSummary>
     */
    public function __invoke(AccessContext $context): array
    {
        return $this->projects->visibleTo($context);
    }
}

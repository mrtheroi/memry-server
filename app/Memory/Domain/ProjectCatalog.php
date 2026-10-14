<?php

namespace App\Memory\Domain;

use App\Team\Domain\AccessContext;

/**
 * Read side of the projects, for the dashboard.
 */
interface ProjectCatalog
{
    /**
     * The projects the context can see, most recently active first. Counts only the memories the caller can read:
     * project-scope memories of the team plus their own personal ones.
     *
     * @return list<ProjectSummary>
     */
    public function visibleTo(AccessContext $context): array;
}

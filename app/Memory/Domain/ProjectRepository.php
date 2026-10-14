<?php

namespace App\Memory\Domain;

use App\Team\Domain\AccessContext;

/**
 * Names must already be normalized with ProjectName::normalize; a null or
 * blank name means "no project" and callers must not call this port.
 */
interface ProjectRepository
{
    public function idFor(int $teamId, string $name): ?int;

    public function getOrCreateId(int $teamId, string $name): int;

    /**
     * The projects the context can see, most recently active first. Counts only the memories the caller can read:
     * project-scope memories of the team plus their own personal ones.
     *
     * @return list<ProjectSummary>
     */
    public function visibleTo(AccessContext $context): array;
}

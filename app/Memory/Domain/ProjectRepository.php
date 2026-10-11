<?php

namespace App\Memory\Domain;

/**
 * Names must already be normalized with ProjectName::normalize; a null or
 * blank name means "no project" and callers must not call this port.
 */
interface ProjectRepository
{
    public function idFor(int $teamId, string $name): ?int;

    public function getOrCreateId(int $teamId, string $name): int;
}

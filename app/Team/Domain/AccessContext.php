<?php

namespace App\Team\Domain;

/**
 * Who is acting and on which team. Fails closed: a denied context reads and writes nothing,
 * and only system() (internal jobs) escapes the tenant filter.
 */
final readonly class AccessContext
{
    private function __construct(
        public ?int $teamId,
        public ?int $userId,
        public ?Role $role,
        public ProjectAccess $projects,
        private bool $system,
    ) {}

    public static function forTeam(int $teamId, int $userId, Role $role, ProjectAccess $projects): self
    {
        return new self($teamId, $userId, $role, $projects, false);
    }

    public static function system(): self
    {
        return new self(null, null, null, ProjectAccess::all(), true);
    }

    public static function denied(?int $userId = null): self
    {
        return new self(null, $userId, null, ProjectAccess::none(), false);
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function isDenied(): bool
    {
        return ! $this->system && $this->teamId === null;
    }
}

<?php

namespace App\Memory\Domain;

use DateTimeImmutable;

/**
 * A project as the dashboard lists it: how many memories the caller can see in it and when the latest changed.
 */
final readonly class ProjectSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public int $memoriesCount,
        public ?DateTimeImmutable $lastActivityAt,
    ) {}
}

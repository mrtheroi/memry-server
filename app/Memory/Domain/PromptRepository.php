<?php

namespace App\Memory\Domain;

use App\Team\Domain\AccessContext;

interface PromptRepository
{
    public function save(UserPrompt $prompt): void;

    /**
     * Move prompts to another project, returning how many were moved.
     * The system context moves the rows of every team.
     */
    public function moveToProject(AccessContext $context, string $from, string $to): int;
}

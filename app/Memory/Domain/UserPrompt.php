<?php

namespace App\Memory\Domain;

final readonly class UserPrompt
{
    public ?string $project;

    public function __construct(
        public int $userId,
        public string $sessionId,
        ?string $project,
        public string $content,
        public ?int $teamId = null,
    ) {
        $this->project = ProjectName::normalize($project);
    }
}

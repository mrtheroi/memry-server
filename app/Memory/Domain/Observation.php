<?php

namespace App\Memory\Domain;

use DateTimeImmutable;

final readonly class Observation
{
    public ?string $project;

    public function __construct(
        public int $userId,
        public string $sessionId,
        public string $type,
        public string $title,
        public string $content,
        ?string $project,
        public string $scope,
        public ?string $topicKey,
        public ?int $id = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?int $teamId = null,
    ) {
        $this->project = ProjectName::normalize($project);
    }

    public function withId(int $id): self
    {
        return new self(
            userId: $this->userId,
            sessionId: $this->sessionId,
            type: $this->type,
            title: $this->title,
            content: $this->content,
            project: $this->project,
            scope: $this->scope,
            topicKey: $this->topicKey,
            id: $id,
            teamId: $this->teamId,
        );
    }
}

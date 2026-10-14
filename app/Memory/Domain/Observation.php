<?php

namespace App\Memory\Domain;

use App\Team\Domain\AccessContext;
use App\Team\Domain\Role;
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
        public ?DateTimeImmutable $createdAt = null,
    ) {
        $this->project = ProjectName::normalize($project);
    }

    /**
     * The author may always delete; owners and admins may delete what they can read. Reading is checked elsewhere.
     */
    public function canBeDeletedBy(AccessContext $context): bool
    {
        return $this->userId === $context->userId
            || in_array($context->role, [Role::Owner, Role::Admin], true);
    }

    public function withTeamId(?int $teamId): self
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
            id: $this->id,
            teamId: $teamId,
        );
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

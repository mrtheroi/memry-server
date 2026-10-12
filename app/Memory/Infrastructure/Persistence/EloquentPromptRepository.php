<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\ProjectRepository;
use App\Memory\Domain\PromptRepository;
use App\Memory\Domain\UserPrompt;

final class EloquentPromptRepository implements PromptRepository
{
    public function __construct(private ProjectRepository $projects = new QueryProjectRepository) {}

    public function save(UserPrompt $prompt): void
    {
        UserPromptRecord::create([
            'user_id' => $prompt->userId,
            'session_id' => $prompt->sessionId,
            'project' => $prompt->project,
            'content' => $prompt->content,
            ...$prompt->teamId === null ? [] : [
                'team_id' => $prompt->teamId,
                'project_id' => $prompt->project === null
                    ? null
                    : $this->projects->getOrCreateId($prompt->teamId, $prompt->project),
            ],
        ]);
    }

    public function moveToProject(string $from, string $to, ?int $userId): int
    {
        return (new MoveToProject)('user_prompts', $from, $to, $userId);
    }
}

<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\ProjectRepository;
use App\Memory\Domain\PromptRepository;
use App\Memory\Domain\UserPrompt;
use App\Team\Application\ProvisionPersonalTeam;
use App\Team\Domain\AccessContext;

final class EloquentPromptRepository implements PromptRepository
{
    public function __construct(private ProjectRepository $projects = new QueryProjectRepository) {}

    public function save(UserPrompt $prompt): void
    {
        // Every row belongs to a team (NOT NULL); a writer that resolved none gets its personal one.
        $teamId = $prompt->teamId ?? app(ProvisionPersonalTeam::class)->forUser($prompt->userId);

        UserPromptRecord::create([
            'user_id' => $prompt->userId,
            'session_id' => $prompt->sessionId,
            'project' => $prompt->project,
            'content' => $prompt->content,
            'team_id' => $teamId,
            'project_id' => $prompt->project === null
                ? null
                : $this->projects->getOrCreateId($teamId, $prompt->project),
        ]);
    }

    public function moveToProject(AccessContext $context, string $from, string $to): int
    {
        return (new MoveToProject)($context, 'user_prompts', $from, $to);
    }
}

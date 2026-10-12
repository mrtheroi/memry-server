<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\PromptRepository;
use App\Team\Domain\AccessContext;

final class MergeProjects
{
    public function __construct(
        private MemoryRepository $memories,
        private PromptRepository $prompts,
    ) {}

    /**
     * Move the rows as they are: topic keys that collide in the target
     * project are only counted, so the user can resolve them.
     *
     * @return array{observations: int, prompts: int, collisions: int}
     */
    public function __invoke(string $from, string $to, AccessContext $context): array
    {
        $collisions = $this->memories->countTopicKeyCollisions($context, $from, $to);

        return [
            'collisions' => $collisions,
            'observations' => $this->memories->moveToProject($context, $from, $to),
            'prompts' => $this->prompts->moveToProject($context, $from, $to),
        ];
    }
}

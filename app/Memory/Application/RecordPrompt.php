<?php

namespace App\Memory\Application;

use App\Memory\Domain\PromptRepository;
use App\Memory\Domain\UserPrompt;
use App\Team\Domain\AccessContext;

final class RecordPrompt
{
    public function __construct(private PromptRepository $prompts, private AuthorizeProjectWrite $authorize) {}

    public function __invoke(UserPrompt $prompt, AccessContext $context): void
    {
        ($this->authorize)($context, $prompt->project);

        $this->prompts->save($prompt);
    }
}

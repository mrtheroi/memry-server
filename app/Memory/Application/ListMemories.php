<?php

namespace App\Memory\Application;

use App\Memory\Domain\InvalidCursor;
use App\Memory\Domain\MemoryPage;
use App\Memory\Domain\MemoryRepository;
use App\Team\Domain\AccessContext;

class ListMemories
{
    public function __construct(private MemoryRepository $memories) {}

    /**
     * @throws InvalidCursor
     */
    public function __invoke(AccessContext $context, ?string $project, ?string $type, ?string $cursor, int $perPage): MemoryPage
    {
        return $this->memories->pageVisible($context, $project, $type, $cursor, $perPage);
    }
}

<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Team\Domain\AccessContext;

class SearchMemories
{
    public function __construct(private MemoryRepository $memories) {}

    /**
     * @return list<Observation>
     */
    public function __invoke(AccessContext $context, string $query, int $limit, ?string $project): array
    {
        return $this->memories->searchVisible($context, $query, $limit, $project);
    }
}

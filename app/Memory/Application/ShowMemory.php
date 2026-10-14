<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Team\Domain\AccessContext;

class ShowMemory
{
    public function __construct(private MemoryRepository $memories) {}

    /**
     * Null for a missing memory and for one the caller may not read, so the two cannot be told apart.
     */
    public function __invoke(AccessContext $context, int $id): ?Observation
    {
        return $this->memories->findVisible($context, $id);
    }
}

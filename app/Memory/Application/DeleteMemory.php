<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryDeletionDenied;
use App\Memory\Domain\MemoryRepository;
use App\Team\Domain\AccessContext;

class DeleteMemory
{
    public function __construct(private MemoryRepository $memories) {}

    /**
     * False for a missing memory and for one the caller may not read, so the two cannot be told apart.
     *
     * @throws MemoryDeletionDenied when the caller can read the memory but may not delete it
     */
    public function __invoke(AccessContext $context, int $id): bool
    {
        $memory = $this->memories->findVisible($context, $id);

        if ($memory === null) {
            return false;
        }

        if (! $memory->canBeDeletedBy($context)) {
            throw MemoryDeletionDenied::notYours();
        }

        return $this->memories->deleteInTeam($context, $id);
    }
}

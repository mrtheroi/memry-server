<?php

namespace App\Usage\Application;

use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessDenied;
use App\Usage\Domain\UsagePoint;
use App\Usage\Domain\UsageRepository;

final class RecordUsage
{
    public function __construct(private UsageRepository $usage) {}

    /**
     * Identity comes only from the context (the token's user and team), never from the points.
     *
     * @param  list<UsagePoint>  $points
     * @return int how many points were newly stored
     */
    public function __invoke(AccessContext $context, array $points): int
    {
        if ($context->isDenied() || $context->isSystem()) {
            throw AccessDenied::noTeam();
        }

        return $this->usage->record($context->teamId, $context->userId, $points);
    }
}

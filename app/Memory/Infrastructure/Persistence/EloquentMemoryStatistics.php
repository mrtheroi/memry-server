<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\MemoryActivity;
use App\Memory\Domain\MemoryStatistics;
use App\Team\Domain\AccessContext;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

class EloquentMemoryStatistics implements MemoryStatistics
{
    public function activity(AccessContext $context, DateTimeImmutable $from, DateTimeImmutable $to): MemoryActivity
    {
        return new MemoryActivity(
            ['memories' => 0, 'projects' => 0, 'session_summaries' => 0],
            [],
            [],
            [],
        );
    }
}

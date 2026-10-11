<?php

namespace App\Team\Infrastructure;

use App\Team\Domain\TeamSlugs;

class RandomTeamSlugs implements TeamSlugs
{
    public function next(): string
    {
        return bin2hex(random_bytes(8));
    }
}

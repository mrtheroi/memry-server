<?php

namespace App\Team\Domain;

use RuntimeException;

class PersonalTeamProvisioningFailed extends RuntimeException
{
    public static function slugCollisions(int $attempts): self
    {
        return new self("Could not find a free team slug after {$attempts} attempts.");
    }
}

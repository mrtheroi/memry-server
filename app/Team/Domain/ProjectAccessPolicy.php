<?php

namespace App\Team\Domain;

interface ProjectAccessPolicy
{
    public function for(Role $role, int $teamId, int $userId): ProjectAccess;
}

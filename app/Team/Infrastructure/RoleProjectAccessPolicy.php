<?php

namespace App\Team\Infrastructure;

use App\Team\Domain\ProjectAccess;
use App\Team\Domain\ProjectAccessPolicy;
use App\Team\Domain\Role;

/**
 * Project grants are not read in this change: members see no project yet.
 */
class RoleProjectAccessPolicy implements ProjectAccessPolicy
{
    public function for(Role $role, int $teamId, int $userId): ProjectAccess
    {
        return match ($role) {
            Role::Owner, Role::Admin => ProjectAccess::all(),
            Role::Member => ProjectAccess::none(),
        };
    }
}

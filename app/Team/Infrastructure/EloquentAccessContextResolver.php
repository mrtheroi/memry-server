<?php

namespace App\Team\Infrastructure;

use App\Models\PersonalAccessToken;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
use App\Team\Domain\ProjectAccessPolicy;
use App\Team\Domain\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Fails closed: anything that cannot be tied to a membership resolves to denied().
 */
class EloquentAccessContextResolver implements AccessContextResolver
{
    public function __construct(private readonly ProjectAccessPolicy $policy) {}

    public function resolve(?Authenticatable $user): AccessContext
    {
        if ($user === null) {
            return AccessContext::denied();
        }

        $userId = (int) $user->getAuthIdentifier();
        $teamId = $this->tokenTeamId($user) ?? $this->personalTeamId($userId);

        if ($teamId === null) {
            return AccessContext::denied($userId);
        }

        $role = DB::table('team_user')->where('team_id', $teamId)->where('user_id', $userId)->value('role');

        if ($role === null) {
            return AccessContext::denied($userId);
        }

        $role = Role::from($role);

        return AccessContext::forTeam($teamId, $userId, $role, $this->policy->for($role, $teamId, $userId));
    }

    private function personalTeamId(int $userId): ?int
    {
        return DB::table('teams')->where('owner_id', $userId)->where('personal_team', true)->value('id');
    }

    private function tokenTeamId(Authenticatable $user): ?int
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        // Sanctum::actingAs() hands out a Mockery token whose attributes read as false.
        $teamId = $token->team_id;

        return is_int($teamId) && $teamId > 0 ? $teamId : null;
    }
}

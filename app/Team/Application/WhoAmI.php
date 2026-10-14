<?php

namespace App\Team\Application;

use App\Models\User;
use App\Team\Domain\AccessContext;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user and the team the request acts on. The team comes from the AccessContext, so it is the
 * token's team under a bearer token and the personal team under a dashboard session.
 */
class WhoAmI
{
    /**
     * @return array{id: int, email: string, name: string, team: array{id: int, slug: string, personal_team: bool}|null}
     */
    public function __invoke(User $user, AccessContext $context): array
    {
        $team = $context->teamId === null ? null : DB::table('teams')->where('id', $context->teamId)->first(['id', 'slug', 'personal_team']);

        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'team' => $team === null ? null : ['id' => $team->id, 'slug' => $team->slug, 'personal_team' => (bool) $team->personal_team],
        ];
    }
}

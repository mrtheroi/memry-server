<?php

namespace App\Team\Application;

use App\Team\Domain\PersonalTeamProvisioningFailed;
use App\Team\Domain\TeamSlugs;
use Illuminate\Support\Facades\DB;

/**
 * Runs inside the caller's transaction so the team and its owner membership commit together.
 */
class ProvisionPersonalTeam
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TeamSlugs $slugs) {}

    public function forUser(int $userId): int
    {
        $teamId = $this->findOrCreateTeam($userId);

        // Also heals a personal team that lost (or never got) its owner row.
        $now = now();
        DB::statement(
            'INSERT INTO team_user (team_id, user_id, role, created_at, updated_at)
             VALUES (?, ?, \'owner\', ?, ?) ON CONFLICT (team_id, user_id) DO NOTHING',
            [$teamId, $userId, $now, $now],
        );

        return $teamId;
    }

    private function findOrCreateTeam(int $userId): int
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $now = now();
            $inserted = DB::selectOne(
                'INSERT INTO teams (owner_id, slug, personal_team, plan, seats, created_at, updated_at)
                 VALUES (?, ?, true, \'free\', 1, ?, ?) ON CONFLICT DO NOTHING RETURNING id',
                [$userId, $this->slugs->next(), $now, $now],
            );

            if ($inserted !== null) {
                return $inserted->id;
            }

            $existing = DB::table('teams')->where('owner_id', $userId)->where('personal_team', true)->value('id');

            if ($existing !== null) {
                return $existing;
            }
        }

        throw PersonalTeamProvisioningFailed::slugCollisions(self::MAX_ATTEMPTS);
    }
}

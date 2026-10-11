<?php

namespace App\Team\Application;

use App\Memory\Domain\ProjectName;
use App\Memory\Domain\ProjectRepository;
use Illuminate\Support\Facades\DB;

/**
 * Run once after dual-write (task 6) is deployed; re-running is safe; it only
 * fills rows that still have no team. Resumable: each chunk commits as a whole,
 * every UPDATE is guarded with `team_id IS NULL` so a row written by newer code
 * is never overwritten, and user_id / updated_at are never written.
 */
class BackfillTeams
{
    private const USER_TOKEN = 'App\Models\User';

    public function __construct(
        private readonly ProvisionPersonalTeam $provisionPersonalTeam,
        private readonly ProjectRepository $projects,
    ) {}

    /**
     * @return array{teams: int, observations: int, user_prompts: int, tokens: int}
     */
    public function run(int $chunk, ?int $maxChunks = null): array
    {
        return [
            'teams' => $this->provisionTeams($chunk),
            'observations' => $this->backfillRows('observations', $chunk, $maxChunks, true),
            'user_prompts' => $this->backfillRows('user_prompts', $chunk, $maxChunks, false),
            'tokens' => $this->backfillTokens($chunk, $maxChunks),
        ];
    }

    /**
     * Read-only: what is still missing.
     *
     * @return array{observations: int, user_prompts: int, tokens: int, teams_without_owner: int, users_without_personal_team: int}
     */
    public function check(): array
    {
        return [
            'observations' => DB::table('observations')->whereNull('team_id')->count(),
            'user_prompts' => DB::table('user_prompts')->whereNull('team_id')->count(),
            'tokens' => DB::table('personal_access_tokens')->where('tokenable_type', self::USER_TOKEN)->whereNull('team_id')->count(),
            'teams_without_owner' => DB::table('teams')->whereNotExists(
                fn ($q) => $q->from('team_user')->whereColumn('team_user.team_id', 'teams.id')->where('team_user.role', 'owner')
            )->count(),
            'users_without_personal_team' => DB::table('users')->whereNotExists(
                fn ($q) => $q->from('teams')->whereColumn('teams.owner_id', 'users.id')->where('teams.personal_team', true)
                    ->whereExists(fn ($m) => $m->from('team_user')->whereColumn('team_user.team_id', 'teams.id')
                        ->whereColumn('team_user.user_id', 'users.id')->where('team_user.role', 'owner'))
            )->count(),
        ];
    }

    private function provisionTeams(int $chunk): int
    {
        $count = 0;
        $lastId = 0;

        while (($userIds = DB::table('users')->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->pluck('id'))->isNotEmpty()) {
            DB::transaction(function () use ($userIds) {
                foreach ($userIds as $userId) {
                    $this->provisionPersonalTeam->forUser($userId);
                }
            });

            $count += $userIds->count();
            $lastId = $userIds->last();
        }

        return $count;
    }

    private function backfillTokens(int $chunk, ?int $maxChunks): int
    {
        $updated = 0;
        $lastId = 0;

        for ($done = 0; $maxChunks === null || $done < $maxChunks; $done++) {
            $tokens = DB::table('personal_access_tokens')
                ->where('tokenable_type', self::USER_TOKEN)->whereNull('team_id')->where('id', '>', $lastId)
                ->orderBy('id')->limit($chunk)->get(['id', 'tokenable_id']);

            if ($tokens->isEmpty()) {
                break;
            }

            $updated += DB::transaction(function () use ($tokens) {
                $teams = [];
                $affected = 0;
                foreach ($tokens->groupBy('tokenable_id') as $userId => $group) {
                    $teamId = $teams[$userId] ??= $this->provisionPersonalTeam->forUser($userId);
                    $affected += DB::table('personal_access_tokens')->whereIn('id', $group->pluck('id'))
                        ->whereNull('team_id')->update(['team_id' => $teamId]);
                }

                return $affected;
            });

            $lastId = $tokens->last()->id;
        }

        return $updated;
    }

    private function backfillRows(string $table, int $chunk, ?int $maxChunks, bool $audit): int
    {
        $updated = 0;
        $lastId = 0;

        for ($done = 0; $maxChunks === null || $done < $maxChunks; $done++) {
            $rows = DB::table($table)->whereNull('team_id')->where('id', '>', $lastId)
                ->orderBy('id')->limit($chunk)->get(['id', 'user_id', 'project']);

            if ($rows->isEmpty()) {
                break;
            }

            $updated += DB::transaction(function () use ($table, $rows, $audit) {
                $teams = [];
                $affected = 0;
                foreach ($rows->groupBy(fn ($row) => $row->user_id.'|'.ProjectName::normalize($row->project)) as $group) {
                    $userId = $group->first()->user_id;
                    $name = ProjectName::normalize($group->first()->project);
                    // A user who signed up after the teams were provisioned gets theirs now.
                    $teamId = $teams[$userId] ??= $this->provisionPersonalTeam->forUser($userId);
                    $values = ['team_id' => $teamId, 'project_id' => $name === null ? null : $this->projects->getOrCreateId($teamId, $name)];
                    if ($audit) {
                        $values += ['created_by' => $userId, 'updated_by' => $userId];
                    }
                    $affected += DB::table($table)->whereIn('id', $group->pluck('id'))->whereNull('team_id')->update($values);
                }

                return $affected;
            });

            $lastId = $rows->last()->id;
        }

        return $updated;
    }
}

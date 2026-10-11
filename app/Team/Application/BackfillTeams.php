<?php

namespace App\Team\Application;

use App\Memory\Domain\ProjectName;
use App\Memory\Domain\ProjectRepository;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent and resumable: only rows with a NULL team_id are touched, each
 * chunk commits as a whole, and user_id / updated_at are never written.
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
        $teams = $this->provisionTeams();

        return [
            'teams' => count($teams),
            'observations' => $this->backfillRows('observations', $teams, $chunk, $maxChunks, true),
            'user_prompts' => $this->backfillRows('user_prompts', $teams, $chunk, $maxChunks, false),
            'tokens' => $this->backfillTokens($teams),
        ];
    }

    /**
     * Read-only: what is still missing.
     *
     * @return array{observations: int, user_prompts: int, tokens: int, teams_without_owner: int}
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
        ];
    }

    /**
     * @return array<int, int> user id => personal team id
     */
    private function provisionTeams(): array
    {
        $teams = [];
        foreach (DB::table('users')->orderBy('id')->pluck('id') as $userId) {
            $teams[$userId] = DB::transaction(fn () => $this->provisionPersonalTeam->forUser($userId));
        }

        return $teams;
    }

    /**
     * @param  array<int, int>  $teams
     */
    private function backfillTokens(array $teams): int
    {
        $updated = 0;
        DB::transaction(function () use ($teams, &$updated) {
            foreach ($teams as $userId => $teamId) {
                $updated += DB::table('personal_access_tokens')
                    ->where('tokenable_type', self::USER_TOKEN)->where('tokenable_id', $userId)->whereNull('team_id')
                    ->update(['team_id' => $teamId]);
            }
        });

        return $updated;
    }

    /**
     * @param  array<int, int>  $teams
     */
    private function backfillRows(string $table, array &$teams, int $chunk, ?int $maxChunks, bool $audit): int
    {
        $updated = 0;
        $lastId = 0;

        for ($done = 0; $maxChunks === null || $done < $maxChunks; $done++) {
            $rows = DB::table($table)->whereNull('team_id')->where('id', '>', $lastId)
                ->orderBy('id')->limit($chunk)->get(['id', 'user_id', 'project']);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($table, $rows, &$teams, $audit) {
                foreach ($rows->groupBy(fn ($row) => $row->user_id.'|'.ProjectName::normalize($row->project)) as $group) {
                    $userId = $group->first()->user_id;
                    $name = ProjectName::normalize($group->first()->project);
                    // A user who signed up after the teams were provisioned gets theirs now.
                    $teamId = $teams[$userId] ??= $this->provisionPersonalTeam->forUser($userId);
                    $values = ['team_id' => $teamId, 'project_id' => $name === null ? null : $this->projects->getOrCreateId($teamId, $name)];
                    if ($audit) {
                        $values += ['created_by' => $userId, 'updated_by' => $userId];
                    }
                    DB::table($table)->whereIn('id', $group->pluck('id'))->update($values);
                }
            });

            $updated += $rows->count();
            $lastId = $rows->last()->id;
        }

        return $updated;
    }
}

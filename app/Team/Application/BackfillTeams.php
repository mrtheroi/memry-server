<?php

namespace App\Team\Application;

use App\Memory\Domain\ProjectName;
use App\Memory\Domain\ProjectRepository;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent and resumable: rows with a NULL team_id get their team, each chunk
 * commits as a whole, and user_id / updated_at are never written. Rows that
 * already have a team but whose legacy `project` changed since (dual-write
 * does not exist yet) get their project_id reconciled.
 */
class BackfillTeams
{
    private const USER_TOKEN = 'App\Models\User';

    public function __construct(
        private readonly ProvisionPersonalTeam $provisionPersonalTeam,
        private readonly ProjectRepository $projects,
    ) {}

    /**
     * @return array{teams: int, observations: int, user_prompts: int, tokens: int, observations_reconciled: int, user_prompts_reconciled: int}
     */
    public function run(int $chunk, ?int $maxChunks = null): array
    {
        $teams = $this->provisionTeams();

        return [
            'teams' => count($teams),
            'observations' => $this->backfillRows('observations', $teams, $chunk, $maxChunks, true),
            'user_prompts' => $this->backfillRows('user_prompts', $teams, $chunk, $maxChunks, false),
            'tokens' => $this->backfillTokens($teams, $chunk, $maxChunks),
            'observations_reconciled' => $this->reconcileProjects('observations', $chunk, $maxChunks),
            'user_prompts_reconciled' => $this->reconcileProjects('user_prompts', $chunk, $maxChunks),
        ];
    }

    /**
     * Read-only: what is still missing.
     *
     * @return array{observations: int, user_prompts: int, tokens: int, teams_without_owner: int, users_without_personal_team: int, observations_stale_project: int, user_prompts_stale_project: int}
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
            'observations_stale_project' => $this->staleProjects('observations'),
            'user_prompts_stale_project' => $this->staleProjects('user_prompts'),
        ];
    }

    /**
     * Same normalization as ProjectName::normalize() and projects_name_canonical_check.
     */
    public const SQL_NORMALIZED = "NULLIF(regexp_replace(regexp_replace(lower(btrim(t.project, E' \\t\\n\\r\\x0B')), '-{2,}', '-', 'g'), '_{2,}', '_', 'g'), '')";

    private function staleProjects(string $table): int
    {
        return (int) DB::selectOne(
            "SELECT count(*) AS n FROM {$table} t LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.team_id IS NOT NULL AND p.name IS DISTINCT FROM ".self::SQL_NORMALIZED
        )->n;
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
    private function backfillTokens(array &$teams, int $chunk, ?int $maxChunks): int
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

            DB::transaction(function () use ($tokens, &$teams) {
                foreach ($tokens->groupBy('tokenable_id') as $userId => $group) {
                    $teamId = $teams[$userId] ??= $this->provisionPersonalTeam->forUser($userId);
                    DB::table('personal_access_tokens')->whereIn('id', $group->pluck('id'))->update(['team_id' => $teamId]);
                }
            });

            $updated += $tokens->count();
            $lastId = $tokens->last()->id;
        }

        return $updated;
    }

    /**
     * Fixes project_id on rows that already have a team but whose project
     * changed afterwards. Only project_id is written.
     */
    private function reconcileProjects(string $table, int $chunk, ?int $maxChunks): int
    {
        $fixed = 0;
        $lastId = 0;

        for ($done = 0; $maxChunks === null || $done < $maxChunks; $done++) {
            $rows = DB::table($table.' as t')->leftJoin('projects as p', 'p.id', '=', 't.project_id')
                ->whereNotNull('t.team_id')->where('t.id', '>', $lastId)
                ->orderBy('t.id')->limit($chunk)->get(['t.id', 't.team_id', 't.project', 't.project_id', 'p.name as current_name']);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($table, $rows, &$fixed) {
                foreach ($rows as $row) {
                    $name = ProjectName::normalize($row->project);
                    if ($name === $row->current_name) {
                        continue;
                    }
                    DB::table($table)->where('id', $row->id)
                        ->update(['project_id' => $name === null ? null : $this->projects->getOrCreateId($row->team_id, $name)]);
                    $fixed++;
                }
            });

            $lastId = $rows->last()->id;
        }

        return $fixed;
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

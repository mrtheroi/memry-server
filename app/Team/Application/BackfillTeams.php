<?php

namespace App\Team\Application;

use App\Memory\Domain\ProjectName;
use App\Memory\Domain\ProjectRepository;
use Illuminate\Support\Facades\DB;

/**
 * Run once after dual-write (task 6) is deployed; re-running is safe; it only
 * fills rows that still have no team. Resumable: processed per user, each
 * transaction commits as a whole (user row locked first), every UPDATE is
 * guarded with `team_id IS NULL` so a row written by newer code is never
 * overwritten, and user_id / updated_at are never written.
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
        $total = ['teams' => 0, 'observations' => 0, 'user_prompts' => 0, 'tokens' => 0];
        $lastId = 0;

        for ($done = 0; $maxChunks === null || $done < $maxChunks; $done++) {
            $userIds = DB::table('users')->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->pluck('id');

            if ($userIds->isEmpty()) {
                break;
            }

            foreach ($userIds as $userId) {
                foreach ($this->backfillUser($userId, $chunk) ?? [] as $what => $count) {
                    $total[$what] += $count;
                }
            }

            $lastId = $userIds->last();
        }

        return $total;
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
            // A token whose user is gone cannot authenticate, so it needs no team.
            'tokens' => DB::table('personal_access_tokens')->where('tokenable_type', self::USER_TOKEN)->whereNull('team_id')
                ->whereExists(fn ($q) => $q->from('users')->whereColumn('users.id', 'personal_access_tokens.tokenable_id'))->count(),
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

    /**
     * One user, in transactions of at most $chunk rows per table. Lock order: the
     * user row first (FOR SHARE: account deletion takes it FOR UPDATE and also
     * starts at the user row, so the two cannot deadlock, while concurrent
     * readers are not blocked), then the user's other rows. Null when the user
     * no longer exists.
     *
     * @return array{teams: int, observations: int, user_prompts: int, tokens: int}|null
     */
    private function backfillUser(int $userId, int $chunk): ?array
    {
        $result = ['teams' => 1, 'observations' => 0, 'user_prompts' => 0, 'tokens' => 0];

        do {
            $step = DB::transaction(function () use ($userId, $chunk) {
                if (! DB::table('users')->where('id', $userId)->sharedLock()->exists()) {
                    return null;
                }

                $teamId = $this->provisionPersonalTeam->forUser($userId);

                return [
                    'tokens' => $this->backfillTokens($userId, $teamId, $chunk),
                    'observations' => $this->backfillRows('observations', $userId, $teamId, $chunk, true),
                    'user_prompts' => $this->backfillRows('user_prompts', $userId, $teamId, $chunk, false),
                ];
            });

            if ($step === null) {
                return null;
            }

            $more = false;
            foreach ($step as $what => [$read, $updated]) {
                $result[$what] += $updated;
                $more = $more || $read === $chunk;
            }
        } while ($more);

        return $result;
    }

    /**
     * @return array{int, int} rows read, rows updated
     */
    private function backfillTokens(int $userId, int $teamId, int $chunk): array
    {
        $ids = DB::table('personal_access_tokens')
            ->where('tokenable_type', self::USER_TOKEN)->where('tokenable_id', $userId)->whereNull('team_id')
            ->orderBy('id')->limit($chunk)->pluck('id');

        return [$ids->count(), $ids->isEmpty() ? 0 : DB::table('personal_access_tokens')
            ->whereIn('id', $ids)->whereNull('team_id')->update(['team_id' => $teamId])];
    }

    /**
     * @return array{int, int} rows read, rows updated
     */
    private function backfillRows(string $table, int $userId, int $teamId, int $chunk, bool $audit): array
    {
        $rows = DB::table($table)->where('user_id', $userId)->whereNull('team_id')
            ->orderBy('id')->limit($chunk)->get(['id', 'project']);
        $updated = 0;

        foreach ($rows->groupBy(fn ($row) => ProjectName::normalize($row->project)) as $group) {
            $name = ProjectName::normalize($group->first()->project);
            $values = ['team_id' => $teamId, 'project_id' => $name === null ? null : $this->projects->getOrCreateId($teamId, $name)];
            if ($audit) {
                $values += ['created_by' => $userId, 'updated_by' => $userId];
            }
            $updated += DB::table($table)->whereIn('id', $group->pluck('id'))->whereNull('team_id')->update($values);
        }

        return [$rows->count(), $updated];
    }
}

<?php

namespace App\Console\Commands;

use App\Team\Application\BackfillTeams;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('memory:backfill-teams {--chunk=500 : Rows per transaction} {--check : Report what is missing without changing anything}')]
#[Description('Assign personal teams, projects and authorship to rows created before teams existed')]
class BackfillMemoryTeams extends Command
{
    public function handle(BackfillTeams $backfill): int
    {
        if ($this->option('check')) {
            return $this->check($backfill);
        }

        $result = $backfill->run(max(1, (int) $this->option('chunk')));

        foreach ($result as $what => $count) {
            $this->line("{$what}: {$count}");
        }

        return self::SUCCESS;
    }

    private function check(BackfillTeams $backfill): int
    {
        $missing = $backfill->check();

        foreach (['observations', 'user_prompts', 'tokens'] as $what) {
            $this->line("{$what} without team: {$missing[$what]}");
        }
        $this->line("teams without owner: {$missing['teams_without_owner']}");
        $this->line("users without personal team: {$missing['users_without_personal_team']}");
        $this->line("observations with stale project: {$missing['observations_stale_project']}");
        $this->line("user_prompts with stale project: {$missing['user_prompts_stale_project']}");

        return array_sum($missing) > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\ProjectRepository;
use Illuminate\Support\Facades\DB;

class QueryProjectRepository implements ProjectRepository
{
    public function idFor(int $teamId, string $name): ?int
    {
        return DB::table('projects')->where('team_id', $teamId)->where('name', $name)->value('id');
    }

    public function getOrCreateId(int $teamId, string $name): int
    {
        $now = now();
        DB::statement(
            'INSERT INTO projects (team_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (team_id, name) DO NOTHING',
            [$teamId, $name, $now, $now],
        );

        return $this->idFor($teamId, $name);
    }
}

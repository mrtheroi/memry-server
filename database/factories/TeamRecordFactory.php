<?php

namespace Database\Factories;

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamRecord>
 */
class TeamRecordFactory extends Factory
{
    protected $model = TeamRecord::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'slug' => bin2hex(random_bytes(8)),
        ];
    }

    /**
     * A team always has its owner membership: the owner row must be exactly
     * teams.owner_id (enforced by a foreign key).
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (TeamRecord $team) => TeamUserRecord::factory()->create([
            'team_id' => $team->id,
            'user_id' => $team->owner_id,
            'role' => Role::Owner,
        ]));
    }
}

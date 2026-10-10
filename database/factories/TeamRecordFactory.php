<?php

namespace Database\Factories;

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * The team and its owner membership commit together: outside a wrapping
     * transaction (seeders, scripts) the deferred foreign key would otherwise
     * be checked right after the team insert. Related factories call create()
     * on this factory, so they get the same guarantee.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }

    /**
     * A team always has its owner membership: the owner row must be exactly
     * teams.owner_id (enforced by deferred foreign keys).
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

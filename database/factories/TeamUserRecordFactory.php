<?php

namespace Database\Factories;

use App\Models\User;
use App\Team\Domain\Role;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamUserRecord>
 */
class TeamUserRecordFactory extends Factory
{
    protected $model = TeamUserRecord::class;

    public function definition(): array
    {
        return [
            'team_id' => TeamRecord::factory(),
            'user_id' => User::factory(),
            'role' => Role::Member,
        ];
    }
}

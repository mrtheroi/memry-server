<?php

namespace Database\Factories;

use App\Models\User;
use App\Team\Infrastructure\Persistence\TeamRecord;
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
}

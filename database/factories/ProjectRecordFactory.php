<?php

namespace Database\Factories;

use App\Team\Infrastructure\Persistence\ProjectRecord;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectRecord>
 */
class ProjectRecordFactory extends Factory
{
    protected $model = ProjectRecord::class;

    public function definition(): array
    {
        return [
            'team_id' => TeamRecord::factory(),
            'name' => fake()->unique()->slug(2),
        ];
    }
}

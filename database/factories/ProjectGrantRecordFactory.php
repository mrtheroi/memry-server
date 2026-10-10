<?php

namespace Database\Factories;

use App\Models\User;
use App\Team\Infrastructure\Persistence\ProjectGrantRecord;
use App\Team\Infrastructure\Persistence\ProjectRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectGrantRecord>
 */
class ProjectGrantRecordFactory extends Factory
{
    protected $model = ProjectGrantRecord::class;

    public function definition(): array
    {
        return [
            'project_id' => ProjectRecord::factory(),
            'user_id' => User::factory(),
        ];
    }
}

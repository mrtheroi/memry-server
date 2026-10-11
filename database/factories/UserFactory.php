<?php

namespace Database\Factories;

use App\Models\User;
use App\Team\Application\ProvisionPersonalTeam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    protected bool $personalTeam = true;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * For tests about users that have not signed in yet (no team).
     */
    public function withoutPersonalTeam(): static
    {
        $factory = $this->newInstance();
        $factory->personalTeam = false;

        return $factory;
    }

    /**
     * Every real user has a personal team (provisioned at sign-in).
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null)
    {
        if ($this->personalTeam) {
            $instances->each(fn (User $user) => app(ProvisionPersonalTeam::class)->forUser($user->id));
        }

        parent::callAfterCreating($instances, $parent);
    }

    protected function newInstance(array $arguments = []): static
    {
        $factory = parent::newInstance($arguments);
        $factory->personalTeam = $this->personalTeam;

        return $factory;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

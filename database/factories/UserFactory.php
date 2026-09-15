<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Access\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

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
     * A user holding the super admin role: everything, no questions.
     */
    public function superAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(Role::ensureSuperAdmin()));
    }

    /**
     * A user holding one role that grants exactly these permissions.
     */
    public function withPermissions(string ...$permissions): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(
            Role::factory()->granting(...$permissions)->create()
        ));
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

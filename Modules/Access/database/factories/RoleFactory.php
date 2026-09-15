<?php

namespace Modules\Access\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Access\Models\Role;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        static $sequence = 0;

        return [
            // The name is unique in the schema, so a sequence rather than a
            // random draw that can collide.
            'name' => 'Role '.++$sequence,
            'description' => null,
            'is_super_admin' => false,
            'permissions' => [],
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => ['is_super_admin' => true]);
    }

    public function granting(string ...$permissions): static
    {
        return $this->state(fn (): array => ['permissions' => $permissions]);
    }
}

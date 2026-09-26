<?php

namespace Modules\Assets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Assets\Models\AssetType;

/** @extends Factory<AssetType> */
class AssetTypeFactory extends Factory
{
    protected $model = AssetType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement(['Laptop', 'Desktop', 'Monitor', 'Docking Station', 'Keyboard', 'Mouse', 'Webcam', 'Phone']).' '.$this->faker->unique()->numberBetween(1, 99999),
            'is_headset' => false,
            'has_computer_name' => false,
            'is_active' => true,
        ];
    }

    public function computer(): static
    {
        return $this->state(fn (): array => ['has_computer_name' => true]);
    }

    public function headset(): static
    {
        return $this->state(fn (): array => ['is_headset' => true]);
    }
}

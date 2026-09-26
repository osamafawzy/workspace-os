<?php

namespace Modules\Assets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Assets\Models\Manufacturer;

/** @extends Factory<Manufacturer> */
class ManufacturerFactory extends Factory
{
    protected $model = Manufacturer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'is_active' => true,
        ];
    }
}

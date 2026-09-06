<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\Floor;

/** @extends Factory<Floor> */
class FloorFactory extends Factory
{
    protected $model = Floor::class;

    public function definition(): array
    {
        // `level` is unique in the schema, so it has to be a sequence rather
        // than a random draw — random integers collide and make tests flaky
        // for reasons that have nothing to do with what they are testing.
        static $level = 0;

        $current = $level++;

        return [
            'name' => "Floor {$current}",
            'level' => $current,
            'width_m' => 60,
            'depth_m' => 40,
            'description' => null,
            'is_active' => true,
            'plan_path' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

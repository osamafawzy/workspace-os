<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;

/** @extends Factory<FloorObject> */
class FloorObjectFactory extends Factory
{
    protected $model = FloorObject::class;

    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'type' => 'desk',
            'workstation_id' => null,
            'label' => null,
            'x' => 5,
            'y' => 5,
            'z' => 0,
            'width' => 1.4,
            'depth' => 0.75,
            'height' => 0.75,
            'rotation' => 0,
            'props' => null,
            'locked' => false,
        ];
    }

    public function wall(float $length = 5): static
    {
        return $this->state(fn (): array => ['type' => 'wall', 'width' => $length, 'depth' => 0.15, 'height' => 2.8]);
    }
}

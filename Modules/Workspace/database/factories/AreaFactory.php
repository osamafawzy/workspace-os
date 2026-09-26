<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Floor;

/** @extends Factory<Area> */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        static $sequence = 0;

        return [
            'floor_id' => Floor::factory(),
            'name' => 'Area '.++$sequence,
            'code' => null,
            'description' => null,
        ];
    }
}

<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Rack;

/** @extends Factory<Rack> */
class RackFactory extends Factory
{
    protected $model = Rack::class;

    public function definition(): array
    {
        static $sequence = 0;

        return [
            'building_id' => fn (): int => Building::query()->orderBy('id')->value('id') ?? Building::factory()->create()->id,
            'floor_id' => null,
            'number' => sprintf('RACK-%02d', ++$sequence),
            'name' => null,
            'location' => null,
            'description' => null,
        ];
    }
}

<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Settings\Models\Site;
use Modules\Workspace\Models\Building;

/** @extends Factory<Building> */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        static $sequence = 0;

        return [
            'site_id' => Site::factory(),
            'name' => 'Building '.++$sequence,
            'code' => null,
            'address' => null,
            'description' => null,
            'is_active' => true,
        ];
    }
}

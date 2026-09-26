<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\NetworkSwitch;

/** @extends Factory<NetworkSwitch> */
class NetworkSwitchFactory extends Factory
{
    protected $model = NetworkSwitch::class;

    public function definition(): array
    {
        static $sequence = 0;

        return [
            'building_id' => fn (): int => Building::query()->orderBy('id')->value('id') ?? Building::factory()->create()->id,
            'rack_id' => null,
            'number' => sprintf('SW-%02d', ++$sequence),
            'name' => null,
            'model' => null,
            'serial_number' => null,
            'management_ip' => null,
            'port_count' => 48,
            'description' => null,
            'is_active' => true,
        ];
    }
}

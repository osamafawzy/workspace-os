<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;

/** @extends Factory<SwitchPort> */
class SwitchPortFactory extends Factory
{
    protected $model = SwitchPort::class;

    public function definition(): array
    {
        static $sequence = 0;

        $number = ++$sequence;

        return [
            'network_switch_id' => NetworkSwitch::factory(),
            'name' => 'Gi1/0/'.$number,
            'number' => (string) $number,
            'description' => null,
        ];
    }
}

<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Settings\Models\Site;
use Modules\Workspace\Models\Vlan;

/** @extends Factory<Vlan> */
class VlanFactory extends Factory
{
    protected $model = Vlan::class;

    public function definition(): array
    {
        static $sequence = 99;

        return [
            'site_id' => fn (): int => Site::query()->orderBy('id')->value('id') ?? Site::factory()->create()->id,
            'number' => ++$sequence,
            'name' => null,
            'subnet' => null,
            'gateway' => null,
            'description' => null,
        ];
    }
}

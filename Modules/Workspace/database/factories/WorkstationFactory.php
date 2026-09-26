<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;

/** @extends Factory<Workstation> */
class WorkstationFactory extends Factory
{
    protected $model = Workstation::class;

    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'name' => 'WS-'.$this->faker->unique()->numberBetween(1, 99999),
            'status' => WorkstationStatus::Active,
            'notes' => null,
        ];
    }

    /**
     * A desk with its whole record filled in: an area on its own floor, a
     * port on a switch in its own building, and a VLAN at its own site.
     */
    public function wired(array $overrides = []): static
    {
        return $this->state(fn (): array => array_merge([
            'area_id' => fn (array $attributes): int => Area::factory()->create(['floor_id' => $attributes['floor_id']])->id,
            'switch_port_id' => function (array $attributes): int {
                $floor = Floor::query()->findOrFail($attributes['floor_id']);

                return SwitchPort::factory()->create([
                    'network_switch_id' => NetworkSwitch::factory()->create(['building_id' => $floor->building_id])->id,
                ])->id;
            },
            'vlan_id' => function (array $attributes): int {
                $floor = Floor::query()->with('building')->findOrFail($attributes['floor_id']);

                return Vlan::factory()->create(['site_id' => $floor->building->site_id])->id;
            },
            'workstation_number' => (string) $this->faker->numberBetween(100, 999),
            'desk_row' => (string) $this->faker->numberBetween(1, 20),
            'desk_position' => (string) $this->faker->numberBetween(1, 12),
            'port_split_number' => $this->faker->randomElement(['A', 'B']),
            'computer_name' => 'HQ-WS-'.$this->faker->unique()->numerify('####'),
            'pc_serial' => 'PC'.$this->faker->unique()->numerify('########'),
            'monitor_serial' => 'MON'.$this->faker->unique()->numerify('#######'),
            'ip_address' => '10.20.'.$this->faker->numberBetween(0, 255).'.'.$this->faker->numberBetween(1, 254),
            'mac_address' => strtoupper(implode(':', str_split($this->faker->unique()->regexify('[0-9A-F]{12}'), 2))),
        ], $overrides));
    }

    /**
     * A desk already on its floor's map, at x% across and y% down the floor
     * (random when not given), as a workstation map object.
     */
    public function placed(?float $x = null, ?float $y = null, float $rotation = 0): static
    {
        return $this->afterCreating(function (Workstation $desk) use ($x, $y, $rotation): void {
            $floor = $desk->floor;

            FloorObject::query()->create([
                'floor_id' => $floor->getKey(),
                'type' => 'workstation',
                'workstation_id' => $desk->getKey(),
                'x' => round(($x ?? $this->faker->randomFloat(2, 0, 100)) / 100 * $floor->width_m, 3),
                'y' => round(($y ?? $this->faker->randomFloat(2, 0, 100)) / 100 * $floor->depth_m, 3),
                'width' => 1.4,
                'depth' => 1.5,
                'height' => 0.75,
                'rotation' => $rotation,
            ]);
        });
    }

    public function status(WorkstationStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}

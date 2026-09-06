<?php

namespace Modules\Workspace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workspace\Models\Floor;
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
            'notes' => null,
            'position_x' => null,
            'position_y' => null,
        ];
    }

    /** A desk with its patching record filled in. */
    public function wired(array $overrides = []): static
    {
        return $this->state(fn (): array => array_merge([
            'site_location' => 'HQ Tower B',
            'zone_number' => 'Z'.$this->faker->numberBetween(1, 9),
            'workstation_number' => (string) $this->faker->numberBetween(100, 999),
            'port_split_number' => $this->faker->randomElement(['A', 'B']),
            'switch_number' => 'SW-'.$this->faker->numerify('0#'),
            'interface_number' => 'Gi1/0/'.$this->faker->numberBetween(1, 48),
            'computer_name' => 'HQ-WS-'.$this->faker->unique()->numerify('####'),
            'mac_address' => strtoupper(implode(':', str_split($this->faker->unique()->regexify('[0-9A-F]{12}'), 2))),
        ], $overrides));
    }

    /** A desk that has already been pinned to a spot on the floor plan. */
    public function placed(?float $x = null, ?float $y = null): static
    {
        return $this->state(fn (): array => [
            'position_x' => $x ?? $this->faker->randomFloat(2, 0, 100),
            'position_y' => $y ?? $this->faker->randomFloat(2, 0, 100),
        ]);
    }
}

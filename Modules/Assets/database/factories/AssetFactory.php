<?php

namespace Modules\Assets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Employees\Models\Employee;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'asset_model_id' => AssetModel::factory(),
            'asset_type_id' => fn (array $attributes): int => AssetModel::query()->findOrFail($attributes['asset_model_id'])->asset_type_id,
            'serial_number' => strtoupper($this->faker->unique()->bothify('SN########??')),
            'asset_tag' => 'AT-'.$this->faker->unique()->numerify('######'),
            'status' => AssetStatus::Available,
            'condition' => AssetCondition::Good,
            'purchase_date' => $this->faker->dateTimeBetween('-3 years', '-1 month'),
        ];
    }

    public function assignedTo(?Employee $employee = null): static
    {
        return $this->state(fn (): array => [
            'employee_id' => $employee?->getKey() ?? Employee::factory(),
        ]);
    }
}

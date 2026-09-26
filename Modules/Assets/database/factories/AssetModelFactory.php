<?php

namespace Modules\Assets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;

/** @extends Factory<AssetModel> */
class AssetModelFactory extends Factory
{
    protected $model = AssetModel::class;

    public function definition(): array
    {
        return [
            'manufacturer_id' => Manufacturer::factory(),
            'asset_type_id' => AssetType::factory(),
            'name' => 'Model '.$this->faker->unique()->bothify('??-####'),
            'is_active' => true,
        ];
    }
}

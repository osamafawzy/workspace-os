<?php

namespace Modules\Settings\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Settings\Models\Site;

/** @extends Factory<Site> */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        static $sequence = 0;

        return [
            'name' => 'Site '.++$sequence,
            'code' => null,
            'city' => null,
            'description' => null,
            'is_active' => true,
        ];
    }
}

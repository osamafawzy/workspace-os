<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Assets\Database\Factories\ManufacturerFactory;
use Modules\Settings\Models\Lookup;

/** Who makes it: Dell, Lenovo, Jabra… */
class Manufacturer extends Lookup
{
    /** @use HasFactory<ManufacturerFactory> */
    use HasFactory;

    protected static function newFactory(): ManufacturerFactory
    {
        return ManufacturerFactory::new();
    }

    /** @return HasMany<AssetModel, $this> */
    public function assetModels(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }

    public function isInUse(): bool
    {
        return $this->assetModels()->exists() || parent::isInUse();
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}

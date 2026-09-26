<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Assets\Database\Factories\AssetModelFactory;
use Modules\Settings\Models\Lookup;

/**
 * One manufacturer's product of one type: a Dell Latitude 5440 laptop.
 *
 * @property int $manufacturer_id
 * @property int $asset_type_id
 */
class AssetModel extends Lookup
{
    /** @use HasFactory<AssetModelFactory> */
    use HasFactory;

    protected $fillable = [
        'manufacturer_id',
        'asset_type_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected static function newFactory(): AssetModelFactory
    {
        return AssetModelFactory::new();
    }

    /** @return BelongsTo<Manufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /** @return BelongsTo<AssetType, $this> */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function isInUse(): bool
    {
        return $this->assets()->exists() || parent::isInUse();
    }

    /** "Dell Latitude 5440". */
    public function fullName(): string
    {
        return trim(($this->manufacturer?->name ?? '').' '.$this->name);
    }

    public function auditLabel(): string
    {
        return $this->fullName();
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}

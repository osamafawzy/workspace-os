<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Assets\Database\Factories\AssetTypeFactory;
use Modules\Settings\Models\Lookup;

/**
 * What kind of thing it is: Laptop, Desktop, Monitor, Headset…
 *
 * @property bool $is_headset
 * @property bool $has_computer_name
 */
class AssetType extends Lookup
{
    /** @use HasFactory<AssetTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_headset',
        'has_computer_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_headset' => 'boolean',
            'has_computer_name' => 'boolean',
        ];
    }

    protected static function newFactory(): AssetTypeFactory
    {
        return AssetTypeFactory::new();
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @return HasMany<AssetModel, $this> */
    public function assetModels(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }

    public function isInUse(): bool
    {
        return $this->assets()->exists() || $this->assetModels()->exists() || parent::isInUse();
    }

    /** @param  Builder<AssetType>  $query */
    public function scopeHeadsets(Builder $query): void
    {
        $query->where('is_headset', true);
    }

    /**
     * The headset type to use when nobody has said which: the first active one,
     * or a "Headset" type made on the spot so the headset screens always have
     * one to offer.
     */
    public static function defaultHeadsetType(): self
    {
        return static::query()->headsets()->where('is_active', true)->orderBy('name')->first()
            ?? static::query()->firstOrCreate(['name' => 'Headset'], ['is_headset' => true, 'is_active' => true]);
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}

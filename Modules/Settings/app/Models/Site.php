<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Settings\Database\Factories\SiteFactory;

/** A company site, e.g. "Alexandria Site". */
class Site extends Lookup
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'city',
        'description',
        'is_active',
    ];

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }

    public function isInUse(): bool
    {
        return $this->locations()->exists() || parent::isInUse();
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}

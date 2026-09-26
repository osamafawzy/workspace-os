<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Settings\Models\Site;
use Modules\Workspace\Database\Factories\BuildingFactory;

/**
 * A building on a site, e.g. "HQ Tower B" at "Alexandria Site".
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property-read Site $site
 */
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'code',
        'address',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): BuildingFactory
    {
        return BuildingFactory::new();
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Floor, $this> */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class);
    }

    /** @return HasMany<Rack, $this> */
    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class);
    }

    /** @return HasMany<NetworkSwitch, $this> */
    public function switches(): HasMany
    {
        return $this->hasMany(NetworkSwitch::class);
    }

    /** @param  Builder<Building>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Floors, racks and switches all hang off a building, so it cannot go while they do. */
    public function isInUse(): bool
    {
        return $this->floors()->exists() || $this->racks()->exists() || $this->switches()->exists();
    }

    /** "Alexandria Site · HQ Tower B" */
    public function fullName(): string
    {
        return $this->site ? "{$this->site->name} · {$this->name}" : $this->name;
    }
}

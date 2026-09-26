<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workspace\Database\Factories\RackFactory;

/**
 * A network rack in a building.
 *
 * @property int $id
 * @property int $building_id
 * @property int|null $floor_id
 * @property string $number
 * @property string|null $name
 * @property-read Building $building
 * @property-read Floor|null $floor
 */
class Rack extends Model
{
    /** @use HasFactory<RackFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'building_id',
        'floor_id',
        'number',
        'name',
        'location',
        'description',
    ];

    protected static function newFactory(): RackFactory
    {
        return RackFactory::new();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return HasMany<NetworkSwitch, $this> */
    public function switches(): HasMany
    {
        return $this->hasMany(NetworkSwitch::class);
    }

    public function isInUse(): bool
    {
        return $this->switches()->exists();
    }

    /** "RACK-02 (IT room east)" */
    public function label(): string
    {
        return $this->name ? "{$this->number} ({$this->name})" : $this->number;
    }

    public function auditLabel(): string
    {
        return $this->label();
    }
}

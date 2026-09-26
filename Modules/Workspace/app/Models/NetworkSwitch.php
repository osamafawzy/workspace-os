<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Workspace\Database\Factories\NetworkSwitchFactory;

/**
 * A network switch in a building. Named NetworkSwitch because `switch` is a
 * PHP keyword and cannot be a class name.
 *
 * @property int $id
 * @property int $building_id
 * @property int|null $rack_id
 * @property string $number
 * @property string|null $name
 * @property-read Building $building
 * @property-read Rack|null $rack
 */
class NetworkSwitch extends Model
{
    /** @use HasFactory<NetworkSwitchFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'building_id',
        'rack_id',
        'number',
        'name',
        'model',
        'serial_number',
        'management_ip',
        'port_count',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'port_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): NetworkSwitchFactory
    {
        return NetworkSwitchFactory::new();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<Rack, $this> */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    /** @return HasMany<SwitchPort, $this> */
    public function ports(): HasMany
    {
        return $this->hasMany(SwitchPort::class);
    }

    /** @return HasManyThrough<Workstation, SwitchPort, $this> */
    public function workstations(): HasManyThrough
    {
        return $this->hasManyThrough(Workstation::class, SwitchPort::class);
    }

    /** @param  Builder<NetworkSwitch>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** A switch cannot be deleted while desks are patched into its ports. */
    public function isInUse(): bool
    {
        return $this->workstations()->exists();
    }

    /** "SW-03 (ALX-F2-ACC-03)" */
    public function label(): string
    {
        return $this->name ? "{$this->number} ({$this->name})" : $this->number;
    }

    public function auditLabel(): string
    {
        return $this->label();
    }
}

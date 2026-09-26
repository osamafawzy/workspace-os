<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workspace\Database\Factories\AreaFactory;

/**
 * A named part of one floor: "Operations Floor", "Zone A", "Training Room 2".
 *
 * @property int $id
 * @property int $floor_id
 * @property string $name
 * @property-read Floor $floor
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'floor_id',
        'name',
        'code',
        'description',
    ];

    protected static function newFactory(): AreaFactory
    {
        return AreaFactory::new();
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return HasMany<Workstation, $this> */
    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    public function auditLabel(): string
    {
        return $this->floor ? "{$this->floor->name} · {$this->name}" : $this->name;
    }
}

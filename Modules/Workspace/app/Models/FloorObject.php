<?php

namespace Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workspace\Database\Factories\FloorObjectFactory;
use Modules\Workspace\FloorMap\FloorObjectType;
use Modules\Workspace\FloorMap\FloorObjectTypes;

/**
 * Something drawn on a floor's map: a workstation, a wall, a door, a rack.
 *
 * Metres from the floor's top-left corner, the centre at (x, y). See the
 * floor_objects migration for the conventions.
 *
 * Not audited per row: a map is saved as a whole, and one save can move three
 * hundred objects. The save writes a single audit entry saying what changed.
 *
 * @property int $id
 * @property int $floor_id
 * @property string $type
 * @property int|null $workstation_id
 * @property string|null $label
 * @property float $x
 * @property float $y
 * @property float $z
 * @property float $width
 * @property float $depth
 * @property float $height
 * @property float $rotation
 * @property array<string, mixed>|null $props
 * @property bool $locked
 */
class FloorObject extends Model
{
    /** @use HasFactory<FloorObjectFactory> */
    use HasFactory;

    protected $fillable = [
        'floor_id',
        'type',
        'workstation_id',
        'label',
        'x',
        'y',
        'z',
        'width',
        'depth',
        'height',
        'rotation',
        'props',
        'locked',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'z' => 'float',
            'width' => 'float',
            'depth' => 'float',
            'height' => 'float',
            'rotation' => 'float',
            'props' => 'array',
            'locked' => 'boolean',
        ];
    }

    protected static function newFactory(): FloorObjectFactory
    {
        return FloorObjectFactory::new();
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return BelongsTo<Workstation, $this> */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function definition(): ?FloorObjectType
    {
        return app(FloorObjectTypes::class)->get($this->type);
    }

    /** @return array<string, mixed> the object as the editor holds it */
    public function toClient(): array
    {
        return [
            'id' => $this->getKey(),
            'type' => $this->type,
            'workstation_id' => $this->workstation_id,
            'label' => $this->label,
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z,
            'width' => $this->width,
            'depth' => $this->depth,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'props' => (object) ($this->props ?? []),
            'locked' => $this->locked,
        ];
    }
}

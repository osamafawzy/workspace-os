<?php

namespace Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Modules\Workspace\Database\Factories\FloorFactory;

/**
 * A physical floor of the building.
 *
 * Floors are the only thing workstations hang off, so this model stays
 * deliberately thin: it names a level, and it owns the desks on it.
 *
 * @property int $id
 * @property string $name
 * @property int $level
 * @property float $width_m
 * @property float $depth_m
 * @property string|null $description
 * @property bool $is_active
 * @property string|null $plan_path
 */
class Floor extends Model
{
    /** @use HasFactory<FloorFactory> */
    use HasFactory;

    /**
     * Memo for planAspectRatio(). `false` means "not measured yet", which is
     * distinct from a measured null — a drawing that cannot be measured should
     * be gone to disk for once, not once per pin.
     */
    private float|null|false $planShape = false;

    protected $fillable = [
        'name',
        'level',
        'width_m',
        'depth_m',
        'description',
        'is_active',
        'plan_path',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'width_m' => 'float',
            'depth_m' => 'float',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): FloorFactory
    {
        return FloorFactory::new();
    }

    /** @return HasMany<Workstation, $this> */
    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    /** @param Builder<Floor> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Bottom of the building first. Every list of floors in the product reads
     * this way, so it lives here rather than being re-typed at each call site.
     *
     * @param  Builder<Floor>  $query
     */
    public function scopeInBuildingOrder(Builder $query): void
    {
        $query->orderBy('level');
    }

    /**
     * True once a plan drawing has been attached — which is what unlocks
     * placing workstations at coordinates rather than just listing them.
     */
    public function hasPlan(): bool
    {
        return filled($this->plan_path);
    }

    /**
     * The shape of the plan drawing itself, or null when there is not one.
     *
     * The drawing is the coordinate space: a desk at 40%, 60% means 40% across
     * and 60% down *the image*. So everything that frames the drawing has to
     * take its shape from the drawing rather than from the floor's metres. A
     * 60 x 40 m floor whose plan was exported at 1088 x 778 is a 1.5 frame
     * around a 1.4 picture, and every pin lands slightly off the desk it is
     * marking.
     *
     * Null for an SVG, whose size is not in a header getimagesize can read, and
     * null for a drawing that has gone missing. Both fall back to the floor's
     * own ratio, which is what the product did before drawings existed.
     */
    public function planAspectRatio(): ?float
    {
        if ($this->planShape === false) {
            $this->planShape = $this->measurePlan();
        }

        return $this->planShape;
    }

    private function measurePlan(): ?float
    {
        if (! $this->hasPlan()) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($this->plan_path)) {
            return null;
        }

        // getimagesize reads the header rather than the whole file, so this is
        // cheap enough to do while rendering. It wants a real path, which the
        // public disk has because it is local; if that ever stops being true
        // this returns null and the floor's own ratio takes over.
        $size = @getimagesize($disk->path($this->plan_path));

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        return round($size[0] / $size[1], 4);
    }

    /** Floor area in square metres. */
    public function area(): float
    {
        return round($this->width_m * $this->depth_m, 2);
    }

    /**
     * Width over depth, which is the shape every drawing of this floor takes:
     * the plan surface in the admin, the read-only plan on the public site,
     * and the slab in the 3D view.
     *
     * Guarded against a zero or missing dimension — a floor with no shape
     * would divide by zero here and take out every page that draws it, so it
     * falls back to the 3:2 the product used before floors had sizes.
     */
    public function aspectRatio(): float
    {
        if ($this->width_m <= 0 || $this->depth_m <= 0) {
            return 1.5;
        }

        return round($this->width_m / $this->depth_m, 4);
    }

    /**
     * Roughly how many desks this floor can hold, at 8 m2 each including
     * circulation. Used to warn when a floor is being asked to hold more desks
     * than it has room for, not to stop anyone doing it — the number is a rule
     * of thumb and the person placing desks can see the real room.
     */
    public function deskCapacity(): int
    {
        return (int) floor($this->area() / 8);
    }
}

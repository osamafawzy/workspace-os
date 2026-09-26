<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Settings\Models\Site;
use Modules\Workspace\Database\Factories\VlanFactory;

/**
 * A VLAN at a site, e.g. 123 "Operations".
 *
 * @property int $id
 * @property int $site_id
 * @property int $number
 * @property string|null $name
 * @property string|null $subnet
 * @property-read Site $site
 */
class Vlan extends Model
{
    /** @use HasFactory<VlanFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'site_id',
        'number',
        'name',
        'subnet',
        'gateway',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }

    protected static function newFactory(): VlanFactory
    {
        return VlanFactory::new();
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Workstation, $this> */
    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    public function isInUse(): bool
    {
        return $this->workstations()->exists();
    }

    /** "123 · Operations" */
    public function label(): string
    {
        return $this->name ? "{$this->number} · {$this->name}" : (string) $this->number;
    }

    public function auditLabel(): string
    {
        return 'VLAN '.$this->label();
    }
}

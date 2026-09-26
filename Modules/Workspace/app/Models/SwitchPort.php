<?php

namespace Modules\Workspace\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Workspace\Database\Factories\SwitchPortFactory;

/**
 * One port on a switch, e.g. Gi2/0/24. At most one desk is patched to it.
 *
 * @property int $id
 * @property int $network_switch_id
 * @property string $name
 * @property string|null $number
 * @property-read NetworkSwitch $networkSwitch
 * @property-read Workstation|null $workstation
 */
class SwitchPort extends Model
{
    /** @use HasFactory<SwitchPortFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'network_switch_id',
        'name',
        'number',
        'description',
    ];

    protected static function newFactory(): SwitchPortFactory
    {
        return SwitchPortFactory::new();
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function networkSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }

    /** @return HasOne<Workstation, $this> */
    public function workstation(): HasOne
    {
        return $this->hasOne(Workstation::class);
    }

    public function isInUse(): bool
    {
        return $this->workstation()->exists();
    }

    /** "SW-03 · Gi2/0/24" */
    public function label(): string
    {
        return $this->networkSwitch ? "{$this->networkSwitch->number} · {$this->name}" : $this->name;
    }

    public function auditLabel(): string
    {
        return $this->label();
    }
}

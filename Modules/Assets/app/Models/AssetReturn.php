<?php

namespace Modules\Assets\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Assets\Enums\AssetCondition;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;

/**
 * An asset coming back: when, in what condition, who brought it and who took
 * it in.
 *
 * @property int $id
 * @property int $asset_assignment_id
 * @property int $asset_id
 * @property int $employee_id
 * @property int|null $handover_form_id
 * @property Carbon $returned_at
 * @property AssetCondition $condition
 * @property string|null $returned_by_name
 * @property string|null $received_by_name
 * @property int|null $site_id
 * @property int|null $location_id
 * @property string|null $notes
 */
class AssetReturn extends Model
{
    use Auditable;

    protected $fillable = [
        'asset_assignment_id',
        'asset_id',
        'employee_id',
        'handover_form_id',
        'returned_at',
        'condition',
        'returned_by_name',
        'received_by',
        'received_by_name',
        'site_id',
        'location_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'returned_at' => 'datetime',
            'condition' => AssetCondition::class,
        ];
    }

    /** @return BelongsTo<AssetAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssetAssignment::class, 'asset_assignment_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<HandoverForm, $this> */
    public function handoverForm(): BelongsTo
    {
        return $this->belongsTo(HandoverForm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function auditLabel(): string
    {
        return 'Return of '.($this->asset?->serial_number ?? 'asset #'.$this->asset_id);
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}

<?php

namespace Modules\Assets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Assets\Enums\AssetCondition;
use Modules\Employees\Models\Employee;

/**
 * One spell an asset spent with an employee: open while they have it, closed
 * when it comes back.
 *
 * Kept in step with the asset by the asset itself ({@see Asset::syncAssignments()}):
 * whatever changes who holds an asset — the Assign screen, a return, an import —
 * closes the old spell and opens the new one. The Assign and Return screens
 * then add what only they know: the form, the notes, the return record.
 *
 * @property int $id
 * @property int $asset_id
 * @property int $employee_id
 * @property int|null $handover_form_id
 * @property Carbon $assigned_at
 * @property string|null $assigned_by_name
 * @property AssetCondition|null $condition_out
 * @property string|null $notes
 * @property Carbon|null $returned_at
 */
class AssetAssignment extends Model
{
    protected $fillable = [
        'asset_id',
        'employee_id',
        'handover_form_id',
        'assigned_at',
        'assigned_by',
        'assigned_by_name',
        'condition_out',
        'notes',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'returned_at' => 'datetime',
            'condition_out' => AssetCondition::class,
        ];
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
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return HasOne<AssetReturn, $this> */
    public function assetReturn(): HasOne
    {
        return $this->hasOne(AssetReturn::class);
    }

    /** @param  Builder<AssetAssignment>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('returned_at');
    }
}

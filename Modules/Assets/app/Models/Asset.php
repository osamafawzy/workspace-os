<?php

namespace Modules\Assets\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Assets\Database\Factories\AssetFactory;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;

/**
 * One physical thing the company owns, known by its serial number.
 *
 * Every save writes the asset's history (see {@see recordHistory()}), whichever
 * screen or import made it, in names rather than ids — "Floor 2 → Floor 3",
 * "Ahmed Hassan (1234567) → nobody".
 *
 * @property int $id
 * @property int $asset_type_id
 * @property int|null $asset_model_id
 * @property string $serial_number
 * @property string|null $asset_tag
 * @property string|null $computer_name
 * @property AssetStatus $status
 * @property AssetCondition|null $condition
 * @property int|null $site_id
 * @property int|null $location_id
 * @property int|null $account_id
 * @property int|null $employee_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $purchase_date
 * @property Carbon|null $warranty_expires_at
 * @property string|null $notes
 * @property string|null $cord_model
 * @property string|null $cord_serial
 * @property AssetCondition|null $cord_condition
 * @property-read AssetType $assetType
 * @property-read AssetModel|null $assetModel
 * @property-read Employee|null $employee
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use Auditable, HasFactory;

    /** Days before a warranty ends that it counts as "expiring". */
    public const WARRANTY_WARNING_DAYS = 30;

    /**
     * What the history records, and what each field is called there.
     *
     * @var array<string, string>
     */
    public const TRACKED = [
        'asset_type_id' => 'Type',
        'asset_model_id' => 'Model',
        'serial_number' => 'Serial Number',
        'asset_tag' => 'Asset Tag',
        'computer_name' => 'Computer Name',
        'status' => 'Status',
        'condition' => 'Condition',
        'site_id' => 'Site',
        'location_id' => 'Location',
        'account_id' => 'Account',
        'employee_id' => 'Assigned To',
        'purchase_date' => 'Purchase Date',
        'warranty_expires_at' => 'Warranty Expiry',
        'notes' => 'Notes',
        'cord_model' => 'Cord Model',
        'cord_serial' => 'Cord S/N',
        'cord_condition' => 'Cord Status',
    ];

    protected $fillable = [
        'asset_type_id',
        'asset_model_id',
        'serial_number',
        'asset_tag',
        'computer_name',
        'status',
        'condition',
        'site_id',
        'location_id',
        'account_id',
        'employee_id',
        'assigned_at',
        'purchase_date',
        'warranty_expires_at',
        'notes',
        'cord_model',
        'cord_serial',
        'cord_condition',
    ];

    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'condition' => AssetCondition::class,
            'cord_condition' => AssetCondition::class,
            'assigned_at' => 'datetime',
            'purchase_date' => 'date',
            'warranty_expires_at' => 'date',
        ];
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }

    /** Why this save is being made; recorded on the history entry it writes. */
    protected ?UpdateReason $updateReason = null;

    /**
     * Say why the next save happens — "replaced, faulty", "lost" — as the
     * Update Assets screen does. It applies to that one save and no further.
     */
    public function withReason(UpdateReason|int|null $reason): static
    {
        $this->updateReason = $reason instanceof UpdateReason
            ? $reason
            : (filled($reason) ? UpdateReason::query()->find($reason) : null);

        return $this;
    }

    protected static function booted(): void
    {
        static::saving(function (Asset $asset): void {
            // Serials and tags are compared as typed on a label, so stray
            // spaces from a scanner or a spreadsheet do not make a second asset.
            $asset->serial_number = trim((string) $asset->serial_number);
            $asset->asset_tag = filled($asset->asset_tag) ? trim((string) $asset->asset_tag) : null;

            // Who holds it and whether it is assigned go together.
            if ($asset->isDirty('employee_id')) {
                if ($asset->employee_id) {
                    $asset->assigned_at = now();

                    if (in_array($asset->status, [AssetStatus::Available, AssetStatus::Returned], true)) {
                        $asset->status = AssetStatus::Assigned;
                    }
                } else {
                    $asset->assigned_at = null;

                    if ($asset->status === AssetStatus::Assigned) {
                        $asset->status = AssetStatus::Available;
                    }
                }
            }
        });

        static::created(function (Asset $asset): void {
            $asset->recordHistory('registered', array_keys(self::TRACKED), fresh: true);

            if ($asset->employee_id) {
                $asset->syncAssignments();
            }
        });

        static::updated(function (Asset $asset): void {
            $changed = array_values(array_intersect(array_keys(self::TRACKED), array_keys($asset->getChanges())));

            if ($changed !== []) {
                $asset->recordHistory(self::eventFor($asset, $changed), $changed);
            }

            if (in_array('employee_id', $changed, true)) {
                $asset->syncAssignments();
            }
        });
    }

    /**
     * Keep the assignment ledger in step with who holds the asset: close
     * whatever spell is still open, and open one with the new holder.
     *
     * Runs whatever changed the holder, so an import or a correction leaves
     * the same trail as the Assign and Return screens — which close and open
     * spells themselves first and then add their form and notes to them.
     */
    public function syncAssignments(): void
    {
        $user = auth()->user();

        $this->assignments()
            ->open()
            ->where('employee_id', '!=', (int) $this->employee_id)
            ->update(['returned_at' => now()]);

        if ($this->employee_id && ! $this->assignments()->open()->where('employee_id', $this->employee_id)->exists()) {
            $this->assignments()->create([
                'employee_id' => $this->employee_id,
                'assigned_at' => $this->assigned_at ?? now(),
                'assigned_by' => $user?->getKey(),
                'assigned_by_name' => $user?->name ?? (app()->runningInConsole() ? 'System (console)' : null),
                'condition_out' => $this->condition,
            ]);
        }
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /** The spell with whoever holds it now, if anybody does. */
    public function openAssignment(): ?AssetAssignment
    {
        return $this->assignments()->open()->latest('assigned_at')->first();
    }

    /** Whether it can be handed to somebody: in stock or back, and with nobody. */
    public function isAssignable(): bool
    {
        return $this->employee_id === null && in_array($this->status, [AssetStatus::Available, AssetStatus::Returned], true);
    }

    /** @return BelongsTo<AssetType, $this> */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /** @return BelongsTo<AssetModel, $this> */
    public function assetModel(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class);
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

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<AssetHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(AssetHistory::class)->latest('id');
    }

    /** "Laptop · Dell Latitude 5440 · 5CG1234XYZ". */
    public function displayLabel(): string
    {
        return collect([$this->assetType?->name, $this->assetModel?->fullName(), $this->serial_number])->filter()->implode(' · ');
    }

    public function auditLabel(): string
    {
        return $this->displayLabel();
    }

    public function warrantyExpired(): bool
    {
        return $this->warranty_expires_at !== null && $this->warranty_expires_at->isPast() && ! $this->warranty_expires_at->isToday();
    }

    /** @param  Builder<Asset>  $query */
    public function scopeWarrantyExpired(Builder $query): void
    {
        $query->whereDate('warranty_expires_at', '<', today());
    }

    /** @param  Builder<Asset>  $query */
    public function scopeWarrantyExpiring(Builder $query, int $days = self::WARRANTY_WARNING_DAYS): void
    {
        $query->whereDate('warranty_expires_at', '>=', today())->whereDate('warranty_expires_at', '<=', today()->addDays($days));
    }

    /** @param  Builder<Asset>  $query */
    public function scopeHeadsets(Builder $query, bool $headsets = true): void
    {
        $query->whereHas('assetType', fn (Builder $type) => $type->where('is_headset', $headsets));
    }

    /**
     * Anything written on the asset or said about it: serial, tag, computer
     * name, who has it (name or OID), its type, model and manufacturer, and
     * where it is. Every word has to match something, so "latitude floor 2"
     * narrows to Latitudes on Floor 2.
     *
     * @param  Builder<Asset>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $words = array_filter(preg_split('/\s+/u', trim(mb_substr($term, 0, 200))) ?: [], fn (string $word): bool => $word !== '');

        if ($words === []) {
            return;
        }

        foreach ($words as $word) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word)).'%';
            $name = fn (Builder $related) => $related->whereRaw("lower(name) like ? escape '!'", [$like]);

            $query->where(fn (Builder $query) => $query
                ->whereRaw("lower(serial_number) like ? escape '!'", [$like])
                ->orWhereRaw("lower(asset_tag) like ? escape '!'", [$like])
                ->orWhereRaw("lower(computer_name) like ? escape '!'", [$like])
                ->orWhereRaw("lower(cord_serial) like ? escape '!'", [$like])
                ->orWhereHas('employee', fn (Builder $employee) => $employee
                    ->whereRaw("lower(name) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(oid) like ? escape '!'", [$like]))
                ->orWhereHas('assetType', $name)
                ->orWhereHas('assetModel', fn (Builder $model) => $model
                    ->whereRaw("lower(name) like ? escape '!'", [$like])
                    ->orWhereHas('manufacturer', $name))
                ->orWhereHas('site', $name)
                ->orWhereHas('location', $name)
                ->orWhereHas('account', $name));
        }
    }

    /**
     * The event a save is filed under: the most telling of what changed.
     *
     * @param  list<string>  $changed
     */
    protected static function eventFor(Asset $asset, array $changed): string
    {
        return match (true) {
            in_array('employee_id', $changed, true) => match (true) {
                $asset->employee_id === null && $asset->status === AssetStatus::Returned => 'returned',
                $asset->employee_id === null => 'unassigned',
                $asset->getOriginal('employee_id') === null => 'assigned',
                default => 'reassigned',
            },
            in_array('status', $changed, true) => 'status changed',
            (bool) array_intersect(['site_id', 'location_id'], $changed) => 'moved',
            (bool) array_intersect(['serial_number', 'asset_tag'], $changed) => 'relabelled',
            default => 'updated',
        };
    }

    /**
     * @param  list<string>  $fields
     */
    protected function recordHistory(string $event, array $fields, bool $fresh = false): void
    {
        $changes = [];

        foreach ($fields as $field) {
            $to = $this->describe($field, $this->getAttribute($field));

            if ($fresh) {
                if ($to !== null) {
                    $changes[$field] = ['from' => null, 'to' => $to];
                }

                continue;
            }

            $from = $this->describe($field, $this->getOriginal($field));

            // Eloquent counts "not loaded" to null as a change; nobody would.
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        if (! $fresh && $changes === []) {
            return;
        }

        $user = auth()->user();

        $this->history()->create([
            'event' => $event,
            'changes' => $changes ?: null,
            // The name as well as the id: renaming a reason later must not
            // rewrite what this entry said at the time.
            'asset_update_reason_id' => $this->updateReason?->getKey(),
            'reason' => $this->updateReason?->name,
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name ?? (app()->runningInConsole() ? 'System (console)' : null),
        ]);

        // A reason is given for one save, not for everything that follows.
        $this->updateReason = null;
    }

    /** A value as a person reads it: a name for an id, a label for a status. */
    public function describe(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'asset_type_id' => AssetType::query()->whereKey($value)->value('name'),
            'asset_model_id' => AssetModel::query()->with('manufacturer')->find($value)?->fullName(),
            'site_id' => Site::query()->whereKey($value)->value('name'),
            'location_id' => Location::query()->whereKey($value)->value('name'),
            'account_id' => Account::query()->whereKey($value)->value('name'),
            'employee_id' => Employee::query()->find($value)?->auditLabel(),
            'status' => ($value instanceof AssetStatus ? $value : AssetStatus::tryFrom((string) $value))?->getLabel(),
            'condition', 'cord_condition' => ($value instanceof AssetCondition ? $value : AssetCondition::tryFrom((string) $value))?->getLabel(),
            'purchase_date', 'warranty_expires_at' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value,
            default => (string) $value,
        };
    }
}

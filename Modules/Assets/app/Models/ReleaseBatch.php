<?php

namespace Modules\Assets\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;

/**
 * A delivery of new assets, staged as "new data" before it is released.
 *
 * A draft can be edited, checked and quick-printed; releasing it creates the
 * assets and archives it, after which it is only ever reprinted.
 *
 * @property int $id
 * @property string|null $number
 * @property string $status
 * @property int $asset_type_id
 * @property int|null $asset_model_id
 * @property int|null $site_id
 * @property int|null $location_id
 * @property int|null $account_id
 * @property Carbon|null $purchase_date
 * @property Carbon|null $warranty_expires_at
 * @property string|null $notes
 * @property array<string, array{at: string, by: ?string, problems: int}>|null $checks
 * @property string|null $created_by_name
 * @property Carbon|null $released_at
 * @property string|null $released_by_name
 * @property int $print_count
 */
class ReleaseBatch extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const ARCHIVED = 'archived';

    /** The three checks, in the order they are run, and what they are called. */
    public const CHECKS = [
        'employees' => 'Check Employees Data',
        'new' => 'Check New Data',
        'old' => 'Check New Data with Old Data',
    ];

    protected $fillable = [
        'asset_type_id',
        'asset_model_id',
        'site_id',
        'location_id',
        'account_id',
        'purchase_date',
        'warranty_expires_at',
        'notes',
        'created_by',
        'created_by_name',
    ];

    protected $attributes = [
        'status' => self::DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'warranty_expires_at' => 'date',
            'checks' => 'array',
            'released_at' => 'datetime',
            'print_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (ReleaseBatch $batch): void {
            $batch->forceFill(['number' => sprintf('RB-%s-%05d', $batch->created_at->format('Y'), $batch->getKey())])->saveQuietly();
        });
    }

    /** @return HasMany<ReleaseBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReleaseBatchItem::class);
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

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isArchived(): bool
    {
        return $this->status === self::ARCHIVED;
    }

    /** "Laptop · Dell Latitude 5440". */
    public function describe(): string
    {
        return collect([$this->assetType?->name, $this->assetModel?->fullName()])->filter()->implode(' · ');
    }

    public function auditLabel(): string
    {
        return (string) ($this->number ?? 'Release batch');
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}

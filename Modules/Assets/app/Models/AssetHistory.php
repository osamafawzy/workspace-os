<?php

namespace Modules\Assets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing that happened to an asset. Append-only, like the audit log.
 *
 * @property int $id
 * @property int $asset_id
 * @property string $event
 * @property array<string, array{from: mixed, to: mixed}>|null $changes
 * @property int|null $asset_update_reason_id
 * @property string|null $reason the reason's name as it read when it was given
 * @property string|null $user_name
 */
class AssetHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'asset_history';

    protected $fillable = [
        'asset_id',
        'event',
        'changes',
        'asset_update_reason_id',
        'reason',
        'user_id',
        'user_name',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Asset history cannot be changed.'));
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UpdateReason, $this> */
    public function updateReason(): BelongsTo
    {
        return $this->belongsTo(UpdateReason::class, 'asset_update_reason_id');
    }
}

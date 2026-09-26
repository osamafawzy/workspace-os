<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Settings\Models\Lookup;

/**
 * Why an asset was changed or replaced — the company's own list, managed under
 * Settings and chosen on the Update Assets screen.
 */
class UpdateReason extends Lookup
{
    protected $table = 'asset_update_reasons';

    /** @return HasMany<AssetHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(AssetHistory::class, 'asset_update_reason_id');
    }

    /** A reason already given for a change stays; retire it instead of deleting it. */
    public function isInUse(): bool
    {
        return $this->history()->exists() || parent::isInUse();
    }

    public function auditModule(): string
    {
        return 'Assets';
    }
}

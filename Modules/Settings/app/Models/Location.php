<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A place within a site where assets live, e.g. "IT Store Room". */
class Location extends Lookup
{
    protected $fillable = [
        'site_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function auditLabel(): string
    {
        return $this->site ? "{$this->name} ({$this->site->name})" : $this->name;
    }
}

<?php

namespace Modules\Settings\Models;

use App\Support\Audit\Auditable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A row in one of the managed lists: a site, a location, an account, a
 * department. They share a shape — a name, an optional short code, whether it
 * is still offered — and differ only in what else they carry.
 */
abstract class Lookup extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only what can still be chosen. A retired site stays on the records that
     * already use it but stops appearing in new dropdowns.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('name');
    }

    /** @var array<class-string<Lookup>, list<Closure(Lookup): bool>> */
    protected static array $usageChecks = [];

    /**
     * Lets another module say "this row is in use while I point at it".
     *
     * Settings cannot know about the modules built on top of it — a site does
     * not know buildings exist — so each module registers its own references
     * from its service provider, and deleting stays safe as modules arrive.
     *
     * @param  Closure(static): bool  $check
     */
    public static function inUseWhen(Closure $check): void
    {
        static::$usageChecks[static::class][] = $check;
    }

    /**
     * Whether anything still points at this row. A row in use cannot be
     * deleted — retire it by switching it off instead.
     */
    public function isInUse(): bool
    {
        foreach (static::$usageChecks[static::class] ?? [] as $check) {
            if ($check($this)) {
                return true;
            }
        }

        return false;
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}

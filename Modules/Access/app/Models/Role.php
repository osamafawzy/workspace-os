<?php

namespace Modules\Access\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Access\Database\Factories\RoleFactory;

/**
 * A named set of permissions handed to users.
 *
 * A role with `is_super_admin` set is not a role with every box ticked — it is
 * a role the permission checks do not apply to at all. That matters the day a
 * module adds a permission: a super admin has it already, where "every box
 * ticked" would quietly be one box short.
 *
 * @property array<int, string>|null $permissions
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SUPER_ADMIN = 'Super Admin';

    protected $fillable = [
        'name',
        'description',
        'is_super_admin',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
        ];
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function grants(string $permission): bool
    {
        return $this->is_super_admin || in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * The super admin role, made if there is not one yet. Found by the flag
     * rather than the name, so renaming it does not produce a second one.
     */
    public static function ensureSuperAdmin(): self
    {
        return static::query()->where('is_super_admin', true)->oldest('id')->first()
            ?? static::query()->create([
                'name' => self::SUPER_ADMIN,
                'description' => 'Full access to everything, including permissions added later.',
                'is_super_admin' => true,
                'permissions' => [],
            ]);
    }

    /**
     * Whether somebody would still hold super admin if this role, or this
     * user, were taken out of the picture.
     *
     * Every guard against locking the building out of its own admin panel
     * asks this one question: deleting the last super admin, taking the flag
     * off the only super admin role, removing the role from the last person
     * who holds it.
     */
    public static function otherSuperAdminsExist(?self $exceptRole = null, ?User $exceptUser = null): bool
    {
        return User::query()
            ->when($exceptUser, fn (Builder $query) => $query->whereKeyNot($exceptUser->getKey()))
            ->whereHas('roles', fn (Builder $query) => $query
                ->where('is_super_admin', true)
                ->when($exceptRole, fn (Builder $query) => $query->whereKeyNot($exceptRole->getKey())))
            ->exists();
    }
}

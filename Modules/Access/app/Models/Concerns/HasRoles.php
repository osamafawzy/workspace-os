<?php

namespace Modules\Access\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Access\Models\Role;

/**
 * Roles on the user model.
 *
 * Every check reads the loaded `roles` relation, so a page asking twenty
 * permission questions about the same user runs one query, not twenty.
 */
trait HasRoles
{
    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->is_super_admin);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->grants($permission));
    }
}

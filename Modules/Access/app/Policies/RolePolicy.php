<?php

namespace Modules\Access\Policies;

use App\Models\User;
use Modules\Access\Models\Role;

/**
 * Who may manage roles.
 *
 * A super admin role is only touched by super admins — otherwise "edit roles"
 * would let anyone hand themselves everything by editing the one role that
 * already has it. And a role cannot be deleted if that would leave nobody
 * holding super admin.
 */
class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('roles.create');
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.update')
            && ($actor->isSuperAdmin() || ! $role->is_super_admin);
    }

    public function delete(User $actor, Role $role): bool
    {
        if (! $actor->hasPermission('roles.delete')) {
            return false;
        }

        if (! $role->is_super_admin) {
            return true;
        }

        return $actor->isSuperAdmin()
            && (! $role->users()->exists() || Role::otherSuperAdminsExist(exceptRole: $role));
    }
}

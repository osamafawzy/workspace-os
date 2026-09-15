<?php

namespace Modules\Access\Policies;

use App\Models\User;
use Modules\Access\Models\Role;

/**
 * Who may manage admin accounts.
 *
 * Beyond the plain permissions, two rules keep the panel from being taken over
 * or locked:
 *
 *   - a super admin account can only be edited or deleted by another super
 *     admin, so holding "edit users" is not a route to taking one over;
 *   - nobody deletes their own account, and nobody deletes the last super
 *     admin, because either can leave the building with no way back into the
 *     panel short of a terminal.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.update')
            && ($actor->isSuperAdmin() || ! $user->isSuperAdmin());
    }

    public function delete(User $actor, User $user): bool
    {
        if (! $actor->hasPermission('users.delete') || $actor->is($user)) {
            return false;
        }

        if (! $user->isSuperAdmin()) {
            return true;
        }

        return $actor->isSuperAdmin() && Role::otherSuperAdminsExist(exceptUser: $user);
    }
}

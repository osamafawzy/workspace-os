<?php

namespace Modules\Settings\Policies;

use App\Models\User;
use Modules\Settings\Models\Lookup;

/** One set of permissions covers every managed list. */
class LookupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('lookups.view');
    }

    public function view(User $user, Lookup $lookup): bool
    {
        return $user->hasPermission('lookups.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('lookups.create');
    }

    public function update(User $user, Lookup $lookup): bool
    {
        return $user->hasPermission('lookups.update');
    }

    public function delete(User $user, Lookup $lookup): bool
    {
        return $user->hasPermission('lookups.delete') && ! $lookup->isInUse();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('lookups.delete');
    }
}

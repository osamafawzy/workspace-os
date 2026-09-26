<?php

namespace Modules\Workspace\Policies;

use App\Models\User;
use Modules\Workspace\Models\Area;

/** An area is part of its floor, so dividing a floor up is editing the floor. */
class AreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('floors.view');
    }

    public function view(User $user, Area $area): bool
    {
        return $user->hasPermission('floors.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('floors.update');
    }

    public function update(User $user, Area $area): bool
    {
        return $user->hasPermission('floors.update');
    }

    /** Desks in a deleted area stay on the floor, just without an area. */
    public function delete(User $user, Area $area): bool
    {
        return $user->hasPermission('floors.update');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('floors.update');
    }
}

<?php

namespace Modules\Workspace\Policies;

use App\Models\User;
use Modules\Workspace\Models\Building;

/** Buildings are managed with the floor permissions: they are Floor Setup. */
class BuildingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('floors.view');
    }

    public function view(User $user, Building $building): bool
    {
        return $user->hasPermission('floors.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('floors.create');
    }

    public function update(User $user, Building $building): bool
    {
        return $user->hasPermission('floors.update');
    }

    /** Not while floors, racks or switches are still in it. */
    public function delete(User $user, Building $building): bool
    {
        return $user->hasPermission('floors.delete') && ! $building->isInUse();
    }
}

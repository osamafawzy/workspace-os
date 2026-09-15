<?php

namespace Modules\Workspace\Policies;

use App\Models\User;
use Modules\Workspace\Models\Floor;

class FloorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('floors.view');
    }

    public function view(User $user, Floor $floor): bool
    {
        return $user->hasPermission('floors.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('floors.create');
    }

    public function update(User $user, Floor $floor): bool
    {
        return $user->hasPermission('floors.update');
    }

    public function delete(User $user, Floor $floor): bool
    {
        return $user->hasPermission('floors.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('floors.delete');
    }

    /**
     * Moving desks around the drawing, filling areas, auto-arranging, and
     * setting the floor's size from its drawing. Separate from editing the
     * floor's record, because the person who lays out the room is often not
     * the person who should be renaming or deleting floors.
     */
    public function arrange(User $user, Floor $floor): bool
    {
        return $user->hasPermission('floors.arrange');
    }
}

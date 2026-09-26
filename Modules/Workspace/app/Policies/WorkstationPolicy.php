<?php

namespace Modules\Workspace\Policies;

use App\Models\User;
use Modules\Workspace\Models\Workstation;

class WorkstationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('workstations.view');
    }

    public function view(User $user, Workstation $workstation): bool
    {
        return $user->hasPermission('workstations.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workstations.create');
    }

    public function update(User $user, Workstation $workstation): bool
    {
        return $user->hasPermission('workstations.update');
    }

    public function delete(User $user, Workstation $workstation): bool
    {
        return $user->hasPermission('workstations.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('workstations.delete');
    }

    /** Duplicating makes a new desk, so it needs the create permission. */
    public function replicate(User $user, Workstation $workstation): bool
    {
        return $user->hasPermission('workstations.create');
    }

    /** Importing creates and updates desks in bulk, so it needs those too. */
    public function import(User $user): bool
    {
        return $user->hasPermission('workstations.import')
            && $user->hasPermission('workstations.create');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('workstations.export');
    }
}

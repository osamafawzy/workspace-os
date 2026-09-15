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
}

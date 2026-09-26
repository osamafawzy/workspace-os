<?php

namespace Modules\Assets\Policies;

use App\Models\User;
use Modules\Assets\Models\AssetReturn;

/** Returns are recorded by the Return Assets screen and never edited afterwards. */
class AssetReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('assignments.view');
    }

    public function view(User $user, AssetReturn $return): bool
    {
        return $user->hasPermission('assignments.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AssetReturn $return): bool
    {
        return false;
    }

    public function delete(User $user, AssetReturn $return): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('assignments.view') && $user->hasPermission('assets.export');
    }
}

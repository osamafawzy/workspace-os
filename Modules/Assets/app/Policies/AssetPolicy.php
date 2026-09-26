<?php

namespace Modules\Assets\Policies;

use App\Models\User;
use Modules\Assets\Models\Asset;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('assets.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->hasPermission('assets.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('assets.create');
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->hasPermission('assets.update');
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $user->hasPermission('assets.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('assets.delete');
    }

    /** Importing creates and updates assets in bulk. */
    public function import(User $user): bool
    {
        return $user->hasPermission('assets.import') && $user->hasPermission('assets.create');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('assets.export');
    }
}

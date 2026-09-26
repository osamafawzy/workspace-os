<?php

namespace Modules\Assets\Policies;

use App\Models\User;
use Modules\Settings\Models\Lookup;

/** Manufacturers, asset types and asset models share one set of permissions. */
class CataloguePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('catalogue.view');
    }

    public function view(User $user, Lookup $entry): bool
    {
        return $user->hasPermission('catalogue.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('catalogue.create');
    }

    public function update(User $user, Lookup $entry): bool
    {
        return $user->hasPermission('catalogue.update');
    }

    /** Refused while anything still points at it; retire it instead. */
    public function delete(User $user, Lookup $entry): bool
    {
        return $user->hasPermission('catalogue.delete') && ! $entry->isInUse();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('catalogue.delete');
    }
}

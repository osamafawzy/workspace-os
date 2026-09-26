<?php

namespace Modules\Workspace\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Racks, switches, ports and VLANs share one set of permissions.
 *
 * Deleting is refused while something depends on the record — a rack with
 * switches in it, a switch or port with desks patched to it, a VLAN desks are
 * on — so nothing is quietly unlinked by a delete somebody did not think
 * through.
 */
class NetworkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('network.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission('network.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('network.create');
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission('network.update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission('network.delete')
            && ! (method_exists($record, 'isInUse') && $record->isInUse());
    }
}

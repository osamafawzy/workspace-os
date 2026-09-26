<?php

namespace Modules\Assets\Policies;

use App\Models\User;
use Modules\Assets\Models\ReleaseBatch;

/**
 * A batch is worked on while it is a draft; once released it is a record, only
 * ever looked at and reprinted.
 */
class ReleaseBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('releases.view');
    }

    public function view(User $user, ReleaseBatch $batch): bool
    {
        return $user->hasPermission('releases.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('releases.view') && $user->hasPermission('releases.manage');
    }

    public function update(User $user, ReleaseBatch $batch): bool
    {
        return $batch->isDraft() && $this->create($user);
    }

    public function delete(User $user, ReleaseBatch $batch): bool
    {
        return $batch->isDraft() && $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    /** Releasing creates assets and hands them out, so it needs assigning too. */
    public function release(User $user, ReleaseBatch $batch): bool
    {
        return $batch->isDraft()
            && $user->hasPermission('releases.release')
            && $user->hasPermission('assignments.assign');
    }
}

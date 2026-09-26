<?php

namespace Modules\Assets\Policies;

use App\Models\User;
use Modules\Assets\Models\HandoverForm;

/**
 * The papers. Nobody edits or deletes one: a signed form is a record, and a
 * mistake is put right by a return and a new handover, which leave papers of
 * their own.
 */
class HandoverFormPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('assignments.view');
    }

    public function view(User $user, HandoverForm $form): bool
    {
        return $user->hasPermission('assignments.view');
    }

    public function print(User $user, HandoverForm $form): bool
    {
        return $user->hasPermission('assignments.view') && $user->hasPermission('assignments.print');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, HandoverForm $form): bool
    {
        return false;
    }

    public function delete(User $user, HandoverForm $form): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

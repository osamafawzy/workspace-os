<?php

namespace Modules\Audit\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * The log can be read, never written, changed or deleted from the panel —
 * not even by a super admin. A log its subjects can edit is not evidence.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('audit.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->hasPermission('audit.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

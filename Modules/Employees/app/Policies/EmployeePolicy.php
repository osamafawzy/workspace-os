<?php

namespace Modules\Employees\Policies;

use App\Models\User;
use Modules\Employees\Models\Employee;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employees.view');
    }

    /**
     * The national ID, the home address and the emergency contacts. Checked
     * wherever they are shown, edited, imported or exported.
     */
    public function viewSensitive(User $user): bool
    {
        return $user->hasPermission('employees.view') && $user->hasPermission('employees.view_sensitive');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employees.update');
    }

    /** Not while something still points at them, such as assets they hold. */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employees.delete') && ! $employee->isInUse();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('employees.delete');
    }

    /** Importing creates and updates employees in bulk. */
    public function import(User $user): bool
    {
        return $user->hasPermission('employees.import')
            && $user->hasPermission('employees.create');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('employees.export');
    }
}

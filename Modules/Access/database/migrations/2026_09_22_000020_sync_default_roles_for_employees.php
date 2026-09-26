<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * The Employees module brought its permissions. The default roles pick up the
 * ones their patterns match — `employees.view` for everyone who views, import
 * and export for engineers — and only IT Admin (everything) gets
 * `employees.view_sensitive`. Nothing is removed from any role.
 */
return new class extends Migration
{
    public function up(): void
    {
        DefaultRoles::ensure();
        DefaultRoles::sync();
    }

    public function down(): void
    {
        // Permissions added to roles are left in place: somebody may have
        // come to rely on them.
    }
};

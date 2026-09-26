<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * Super Admin, IT Admin, IT Engineer, IT Technician and Viewer, so a fresh
 * install — or this existing one, on its next migrate — has the roles the IT
 * team works with rather than an empty list.
 *
 * Only creates what is missing; a role somebody already edited is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DefaultRoles::ensure();
    }

    public function down(): void
    {
        // Roles are data people go on to edit and assign; rolling back the
        // migration does not delete them from under their users.
    }
};

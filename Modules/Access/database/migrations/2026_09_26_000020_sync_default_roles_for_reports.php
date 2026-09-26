<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * Reports brought their permissions: everyone who views can open them,
 * engineers export, and those who print can print. Nothing is removed.
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
        // Permissions added to roles are left in place.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * Releasing new assets brought its permissions: engineers stage and release
 * batches, everyone who views sees them. Nothing is removed from any role.
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

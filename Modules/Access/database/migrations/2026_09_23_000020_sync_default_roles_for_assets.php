<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * The Assets module brought its permissions: the assets themselves and the
 * catalogue of manufacturers, types and models. The default roles pick up the
 * ones their patterns match. Nothing is removed from any role.
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

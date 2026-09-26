<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * Assigning and returning brought their permissions: engineers and
 * technicians hand assets out and take them back, everyone who views sees
 * the papers, and those who print may print them. Nothing is removed.
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

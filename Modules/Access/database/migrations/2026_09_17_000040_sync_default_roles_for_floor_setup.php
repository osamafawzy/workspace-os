<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Access\Support\DefaultRoles;

/**
 * Floor Setup brought new permissions — the Network group, and importing and
 * exporting workstations. The default roles pick up the ones their patterns
 * match. Nothing is removed from any role.
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
        // Additive only; there is nothing to take back.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            // All three are optional, and empty is a normal state rather than
            // an unfinished one: a desk exists physically long before anyone
            // records what it is plugged into.

            // A TCP/IP port number. unsignedSmallInteger is exactly the range
            // a port has — 0 to 65535 — so the column itself refuses anything
            // that could not be one, and the form narrows it to 1-65535.
            $table->unsignedSmallInteger('port')->nullable()->after('name');

            // A name, zone or policy id rather than a fixed format: whatever
            // the network is actually labelled with.
            $table->string('firewall', 100)->nullable()->after('port');

            $table->text('notes')->nullable()->after('firewall');
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn(['port', 'firewall', 'notes']);
        });
    }
};

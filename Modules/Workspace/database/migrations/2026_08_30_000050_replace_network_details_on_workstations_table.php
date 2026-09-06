<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Swap the first pass at desk details for the real patching record.
 *
 * `port` and `firewall` were a guess at what gets written down about a desk.
 * The actual sheet is a location (site, zone, desk number) and a patch path
 * (split, switch, interface) with the machine on the end of it, so those two
 * columns go and the eight below take their place.
 *
 * Everything is nullable. A desk is recorded the day it physically exists,
 * long before anyone has traced which switch port it lands on.
 *
 * All eight are strings rather than integers even where they are called
 * "number": real switch and interface labels are alphanumeric — Gi1/0/24,
 * SW-03, A/B — and a column that cannot hold what is written on the equipment
 * is not a record of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn(['port', 'firewall']);
        });

        Schema::table('workstations', function (Blueprint $table) {
            // Where the desk is.
            $table->string('site_location', 150)->nullable()->after('name');
            $table->string('zone_number', 50)->nullable()->after('site_location');
            $table->string('workstation_number', 50)->nullable()->after('zone_number');

            // How it is patched back to the rack.
            $table->string('port_split_number', 50)->nullable()->after('workstation_number');
            $table->string('switch_number', 50)->nullable()->after('port_split_number');
            $table->string('interface_number', 50)->nullable()->after('switch_number');

            // What is plugged into it.
            $table->string('computer_name', 100)->nullable()->after('interface_number');

            // Stored canonically as AA:BB:CC:DD:EE:FF, which is 17 characters.
            // The model normalises whatever format it is pasted in as.
            $table->string('mac_address', 17)->nullable()->after('computer_name');
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn([
                'site_location',
                'zone_number',
                'workstation_number',
                'port_split_number',
                'switch_number',
                'interface_number',
                'computer_name',
                'mac_address',
            ]);
        });

        Schema::table('workstations', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->after('name');
            $table->string('firewall', 100)->nullable()->after('port');
        });
    }
};

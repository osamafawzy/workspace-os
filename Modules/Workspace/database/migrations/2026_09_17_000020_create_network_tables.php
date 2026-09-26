<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The network a desk is patched into, as records rather than as text typed
 * onto each desk.
 *
 * Typed text is how "SW-03", "SW03" and "sw-3" become three switches nobody can
 * filter by. As records, a switch is chosen from a list, its rack comes with
 * it, and "every desk on SW-03" is one filter.
 *
 * Racks and switches belong to a building: labels like RACK-02 and SW-03 are
 * unique within a building, not across a company with several. VLANs belong to
 * a site, because VLAN 123 at one site is a different network from VLAN 123 at
 * another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            // 1–4094 is every VLAN id 802.1Q allows.
            $table->unsignedSmallInteger('number');
            $table->string('name', 100)->nullable();
            // CIDR, e.g. 10.20.30.0/24.
            $table->string('subnet', 50)->nullable();
            $table->string('gateway', 45)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'number']);
        });

        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            // Which floor the rack physically stands on, if it is known.
            $table->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            // What is printed on it: RACK-02.
            $table->string('number', 50);
            // What people call it: "IT room, east wing".
            $table->string('name', 100)->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['building_id', 'number']);
        });

        Schema::create('network_switches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('rack_id')->nullable()->constrained()->nullOnDelete();
            // The label on the front: SW-03.
            $table->string('number', 50);
            // Its hostname, if different: ALX-F2-ACC-03.
            $table->string('name', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('management_ip', 45)->nullable();
            $table->unsignedSmallInteger('port_count')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['building_id', 'number']);
        });

        Schema::create('switch_ports', function (Blueprint $table) {
            $table->id();
            // A port is part of its switch and goes with it. Desks patched to
            // it are unlinked (see workstations.switch_port_id), not deleted.
            $table->foreignId('network_switch_id')->constrained()->cascadeOnDelete();
            // The interface name: Gi2/0/24.
            $table->string('name', 50);
            // The plain port number, if tracked separately: 24.
            $table->string('number', 20)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['network_switch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_ports');
        Schema::dropIfExists('network_switches');
        Schema::dropIfExists('racks');
        Schema::dropIfExists('vlans');
    }
};

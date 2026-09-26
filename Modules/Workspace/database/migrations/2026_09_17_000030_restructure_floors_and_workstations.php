<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Floors join the site → building hierarchy, and a desk's patching record moves
 * from free text to the records it describes.
 *
 * Existing data is carried across, never dropped:
 *
 *   - every existing floor goes into one building. It is named after the
 *     desks' "site location" if they all agree on one, otherwise "Main
 *     Building", under the first site (or a new "Main Site");
 *   - each distinct zone on a floor becomes an area of that floor;
 *   - each distinct switch becomes a switch in the building, each interface a
 *     port on it, and the desk is patched to that port;
 *   - a desk gets status Active if it has a computer name, Available if not;
 *   - anything that no longer has a column of its own — a site location that
 *     differs from the building, a switch with no interface, a port another
 *     desk already holds — is appended to the desk's notes rather than lost.
 *
 * Query builder rather than models, so this keeps meaning the same thing
 * however the models change later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Adding a foreign key through the schema builder makes SQLite
            // rebuild the table, and dropping the old floors table cascades
            // to every workstation on it. SQLite can add a nullable column
            // that references another table in place, so it does that: the
            // same column and the same constraint, without the rebuild.
            DB::statement('ALTER TABLE floors ADD COLUMN building_id INTEGER NULL REFERENCES buildings(id) ON DELETE RESTRICT');
        } else {
            Schema::table('floors', function (Blueprint $table) {
                $table->foreignId('building_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }

        $buildingId = $this->buildingForExistingFloors();

        if ($buildingId !== null) {
            DB::table('floors')->update(['building_id' => $buildingId]);
        }

        Schema::table('floors', function (Blueprint $table) {
            // building_id stays nullable in the schema, and every screen and
            // import requires it. Making it NOT NULL here would be a column
            // change, which SQLite performs by rebuilding the table — and
            // dropping the old floors table cascades to every workstation on
            // it. Not a risk worth taking for a constraint the application
            // already enforces.

            // A floor's name and level were unique across everything; with
            // more than one building they are unique within their building.
            $table->dropUnique(['name']);
            $table->dropUnique(['level']);
            $table->unique(['building_id', 'name']);
            $table->unique(['building_id', 'level']);
        });

        Schema::table('workstations', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('floor_id')->constrained()->nullOnDelete();
            $table->string('desk_row', 20)->nullable()->after('workstation_number');
            $table->string('desk_position', 20)->nullable()->after('desk_row');

            // One port, one desk. Two desks on one port is a patching mistake
            // worth being told about, so the database refuses it.
            $table->foreignId('switch_port_id')->nullable()->unique()->after('desk_position')->constrained()->nullOnDelete();
            $table->foreignId('vlan_id')->nullable()->after('port_split_number')->constrained()->nullOnDelete();

            $table->string('pc_serial', 100)->nullable()->after('computer_name');
            $table->string('monitor_serial', 100)->nullable()->after('pc_serial');
            $table->string('ip_address', 45)->nullable()->after('monitor_serial');

            $table->string('status', 30)->default('active')->after('name')->index();
        });

        $this->carryOverPatching();

        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn(['site_location', 'zone_number', 'switch_number', 'interface_number']);
        });
    }

    /** The building every pre-existing floor goes into, or null if there are none. */
    protected function buildingForExistingFloors(): ?int
    {
        if (! DB::table('floors')->exists()) {
            return null;
        }

        $now = now();

        $siteId = DB::table('sites')->orderBy('id')->value('id')
            ?? DB::table('sites')->insertGetId([
                'name' => 'Main Site',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $locations = DB::table('workstations')
            ->whereNotNull('site_location')
            ->where('site_location', '!=', '')
            ->distinct()
            ->pluck('site_location');

        $name = $locations->count() === 1 ? mb_substr(trim((string) $locations->first()), 0, 100) : 'Main Building';

        return DB::table('buildings')->where('site_id', $siteId)->where('name', $name)->value('id')
            ?? DB::table('buildings')->insertGetId([
                'site_id' => $siteId,
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    protected function carryOverPatching(): void
    {
        $now = now();
        $floors = DB::table('floors')->pluck('building_id', 'id');
        $buildingNames = DB::table('buildings')->pluck('name', 'id');

        /** @var array<string, int> $areas "floor|name" => id */
        $areas = [];
        /** @var array<string, int> $switches "building|number" => id */
        $switches = [];
        /** @var array<string, int> $ports "switch|name" => id */
        $ports = [];
        /** @var array<int, true> $usedPorts */
        $usedPorts = [];

        DB::table('workstations')->orderBy('id')->chunkById(500, function ($desks) use (&$areas, &$switches, &$ports, &$usedPorts, $floors, $buildingNames, $now): void {
            foreach ($desks as $desk) {
                $buildingId = (int) $floors[$desk->floor_id];
                $update = ['status' => filled($desk->computer_name) ? 'active' : 'available'];
                $carried = [];

                $zone = trim((string) $desk->zone_number);
                if ($zone !== '') {
                    $key = $desk->floor_id.'|'.mb_strtolower($zone);
                    $areas[$key] ??= DB::table('areas')->where('floor_id', $desk->floor_id)->where('name', mb_substr($zone, 0, 100))->value('id')
                        ?? DB::table('areas')->insertGetId([
                            'floor_id' => $desk->floor_id,
                            'name' => mb_substr($zone, 0, 100),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $update['area_id'] = $areas[$key];
                }

                $switch = trim((string) $desk->switch_number);
                $interface = trim((string) $desk->interface_number);

                if ($switch !== '') {
                    $key = $buildingId.'|'.mb_strtolower($switch);
                    $switches[$key] ??= DB::table('network_switches')->where('building_id', $buildingId)->where('number', $switch)->value('id')
                        ?? DB::table('network_switches')->insertGetId([
                            'building_id' => $buildingId,
                            'number' => mb_substr($switch, 0, 50),
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                    if ($interface !== '') {
                        $portKey = $switches[$key].'|'.mb_strtolower($interface);
                        $ports[$portKey] ??= DB::table('switch_ports')->where('network_switch_id', $switches[$key])->where('name', $interface)->value('id')
                            ?? DB::table('switch_ports')->insertGetId([
                                'network_switch_id' => $switches[$key],
                                'name' => mb_substr($interface, 0, 50),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);

                        if (isset($usedPorts[$ports[$portKey]])) {
                            $carried[] = "Patched to {$switch} {$interface}, which another desk was already recorded on.";
                        } else {
                            $usedPorts[$ports[$portKey]] = true;
                            $update['switch_port_id'] = $ports[$portKey];
                        }
                    } else {
                        $carried[] = "Switch {$switch} (no interface was recorded).";
                    }
                } elseif ($interface !== '') {
                    $carried[] = "Interface {$interface} (no switch was recorded).";
                }

                $location = trim((string) $desk->site_location);
                if ($location !== '' && $location !== ($buildingNames[$buildingId] ?? null)) {
                    $carried[] = "Site location: {$location}.";
                }

                if ($carried !== []) {
                    $update['notes'] = trim(implode("\n", array_filter([
                        $desk->notes,
                        "Carried over from the old patching fields:\n".implode("\n", $carried),
                    ])));
                }

                DB::table('workstations')->where('id', $desk->id)->update($update);
            }
        });
    }

    /**
     * Written for MySQL / MariaDB, which is what this runs on. On SQLite the
     * foreign key drops below rebuild their tables, and rebuilding floors
     * cascades to its workstations; roll back there only on a copy.
     */
    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->string('site_location', 150)->nullable()->after('name');
            $table->string('zone_number', 50)->nullable()->after('site_location');
            $table->string('switch_number', 50)->nullable()->after('port_split_number');
            $table->string('interface_number', 50)->nullable()->after('switch_number');
        });

        // Put back what the records can still say, so a rollback keeps the
        // patching sheet readable.
        DB::table('workstations')->orderBy('id')->chunkById(500, function ($desks): void {
            foreach ($desks as $desk) {
                $port = $desk->switch_port_id
                    ? DB::table('switch_ports')
                        ->join('network_switches', 'network_switches.id', '=', 'switch_ports.network_switch_id')
                        ->where('switch_ports.id', $desk->switch_port_id)
                        ->first(['switch_ports.name as port', 'network_switches.number as switch'])
                    : null;

                DB::table('workstations')->where('id', $desk->id)->update([
                    'zone_number' => $desk->area_id ? DB::table('areas')->where('id', $desk->area_id)->value('name') : null,
                    'switch_number' => $port?->switch,
                    'interface_number' => $port?->port,
                ]);
            }
        });

        Schema::table('workstations', function (Blueprint $table) {
            $table->dropUnique(['switch_port_id']);
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('switch_port_id');
            $table->dropConstrainedForeignId('vlan_id');
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn(['desk_row', 'desk_position', 'pc_serial', 'monitor_serial', 'ip_address', 'status']);
        });

        Schema::table('floors', function (Blueprint $table) {
            $table->dropUnique(['building_id', 'name']);
            $table->dropUnique(['building_id', 'level']);
            $table->unique('name');
            $table->unique('level');
            $table->dropConstrainedForeignId('building_id');
        });
    }
};

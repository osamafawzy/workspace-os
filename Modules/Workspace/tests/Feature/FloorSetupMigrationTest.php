<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * The migration that moved floors into buildings and desks' free-text patching
 * into switches, ports and areas.
 *
 * It runs once on every existing install, against data nobody here has seen,
 * so it is worth testing properly: roll it back, put in rows shaped the old
 * way, run it forward, and check nothing was lost.
 */
class FloorSetupMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function migration(): object
    {
        return require base_path('Modules/Workspace/database/migrations/2026_09_17_000030_restructure_floors_and_workstations.php');
    }

    public function test_existing_patching_is_carried_into_the_new_records(): void
    {
        $migration = $this->migration();
        $migration->down();

        $now = now();
        $floorId = DB::table('floors')->insertGetId(['name' => 'First Floor', 'level' => 1, 'width_m' => 24, 'depth_m' => 16, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $desk = fn (array $values): int => DB::table('workstations')->insertGetId([
            'floor_id' => $floorId, 'created_at' => $now, 'updated_at' => $now, ...$values,
        ]);

        $a = $desk(['name' => 'A-01', 'site_location' => 'HQ Tower B', 'zone_number' => 'Zone A', 'switch_number' => 'SW-03', 'interface_number' => 'Gi1/0/1', 'computer_name' => 'PC-1']);
        $b = $desk(['name' => 'A-02', 'site_location' => 'HQ Tower B', 'zone_number' => 'Zone A', 'switch_number' => 'SW-03', 'interface_number' => 'Gi1/0/2']);
        // Recorded on the same port as A-01: a mistake in the sheet.
        $c = $desk(['name' => 'A-03', 'site_location' => 'HQ Tower B', 'switch_number' => 'SW-03', 'interface_number' => 'Gi1/0/1', 'notes' => 'By the door.']);
        // A switch with no interface.
        $d = $desk(['name' => 'A-04', 'switch_number' => 'SW-04']);

        $migration->up();

        // One building, named after the location every desk agreed on.
        $building = DB::table('buildings')->first();
        $this->assertSame('HQ Tower B', $building->name);
        $this->assertSame($building->id, DB::table('floors')->where('id', $floorId)->value('building_id'));

        $deskA = Workstation::query()->with(['area', 'switchPort.networkSwitch'])->findOrFail($a);
        $this->assertSame('Zone A', $deskA->area->name);
        $this->assertSame('SW-03', $deskA->networkSwitch()->number);
        $this->assertSame('Gi1/0/1', $deskA->switchPort->name);
        $this->assertSame('active', $deskA->status->value);

        $deskB = Workstation::query()->findOrFail($b);
        $this->assertSame($deskA->area_id, $deskB->area_id);
        $this->assertSame('available', $deskB->status->value);

        // The second desk on a taken port keeps its note and gains one saying so.
        $deskC = Workstation::query()->findOrFail($c);
        $this->assertNull($deskC->switch_port_id);
        $this->assertStringStartsWith('By the door.', $deskC->notes);
        $this->assertStringContainsString('Patched to SW-03 Gi1/0/1, which another desk was already recorded on.', $deskC->notes);

        $this->assertStringContainsString('Switch SW-04 (no interface was recorded).', Workstation::query()->findOrFail($d)->notes);

        $this->assertSame(2, DB::table('network_switches')->count());
        $this->assertSame(2, DB::table('switch_ports')->count());
    }

    public function test_desks_that_disagree_on_their_site_location_keep_it_in_their_notes(): void
    {
        $migration = $this->migration();
        $migration->down();

        $now = now();
        $floorId = DB::table('floors')->insertGetId(['name' => 'Ground', 'level' => 0, 'width_m' => 60, 'depth_m' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('workstations')->insert([
            ['floor_id' => $floorId, 'name' => 'G-01', 'site_location' => 'Tower A', 'created_at' => $now, 'updated_at' => $now],
            ['floor_id' => $floorId, 'name' => 'G-02', 'site_location' => 'Tower B', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration->up();

        $this->assertSame('Main Building', DB::table('buildings')->value('name'));
        $this->assertStringContainsString('Site location: Tower A.', Workstation::query()->where('name', 'G-01')->value('notes'));
    }

    /**
     * The forward migration must not rebuild the floors table: on SQLite a
     * rebuild drops the old table, and that cascades to every desk on it.
     */
    public function test_migrating_forward_never_loses_a_desk(): void
    {
        $migration = $this->migration();
        $migration->down();

        $now = now();
        $floorId = DB::table('floors')->insertGetId(['name' => 'Ground', 'level' => 0, 'width_m' => 60, 'depth_m' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        foreach (range(1, 25) as $n) {
            DB::table('workstations')->insert(['floor_id' => $floorId, 'name' => "G-{$n}", 'created_at' => $now, 'updated_at' => $now]);
        }

        $migration->up();

        $this->assertSame(25, DB::table('workstations')->count());
    }
}

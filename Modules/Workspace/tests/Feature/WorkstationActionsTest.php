<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Settings\Models\Site;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Filament\Admin\Tables\WorkstationTable;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/** Duplicate, and the bulk actions on the workstation list. */
class WorkstationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_duplicating_keeps_what_neighbours_share_and_drops_what_they_cannot(): void
    {
        $original = Workstation::factory()->wired()->placed()->create(['name' => 'WS-024', 'desk_row' => '3', 'desk_position' => '12']);

        Livewire::test(ListWorkstations::class)
            ->callTableAction('replicate', $original, data: ['name' => 'WS-025', 'desk_position' => '13'])
            ->assertHasNoTableActionErrors();

        $copy = Workstation::query()->where('name', 'WS-025')->firstOrFail();

        $this->assertSame($original->floor_id, $copy->floor_id);
        $this->assertSame($original->area_id, $copy->area_id);
        $this->assertSame($original->vlan_id, $copy->vlan_id);
        $this->assertSame('3', $copy->desk_row);
        $this->assertSame('13', $copy->desk_position);

        foreach (['switch_port_id', 'ip_address', 'mac_address', 'computer_name', 'pc_serial', 'monitor_serial'] as $field) {
            $this->assertNull($copy->{$field}, $field);
        }

        // The copy starts in the tray, not on top of the original.
        $this->assertFalse($copy->isPlaced());
    }

    public function test_the_duplicate_suggests_the_next_free_id(): void
    {
        $floor = Floor::factory()->create();
        $original = Workstation::factory()->for($floor)->create(['name' => 'WS-024']);
        Workstation::factory()->for($floor)->create(['name' => 'WS-025']);

        $this->assertSame('WS-026', WorkstationTable::nextFreeName($original));
        $this->assertSame('Reception-copy', WorkstationTable::nextFreeName(Workstation::factory()->create(['name' => 'Reception'])));
    }

    public function test_a_duplicate_cannot_take_an_id_already_on_the_floor(): void
    {
        $floor = Floor::factory()->create();
        $original = Workstation::factory()->for($floor)->create(['name' => 'WS-024']);
        Workstation::factory()->for($floor)->create(['name' => 'WS-099']);

        Livewire::test(ListWorkstations::class)
            ->callTableAction('replicate', $original, data: ['name' => 'WS-099'])
            // Said on the field, before the database is asked.
            ->assertHasTableActionErrors(['name' => 'unique']);

        $this->assertSame(1, Workstation::query()->where('name', 'WS-099')->count());
        $this->assertSame(2, Workstation::query()->count());

        // The same name on another floor is another desk, and allowed.
        Livewire::test(ListWorkstations::class)
            ->callTableAction('replicate', Workstation::factory()->create(['name' => 'WS-001']), data: ['name' => 'WS-099'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, Workstation::query()->where('name', 'WS-099')->count());
    }

    public function test_duplicating_needs_the_create_permission(): void
    {
        $desk = Workstation::factory()->create();

        $this->actingAs(User::factory()->withPermissions('workstations.view', 'workstations.update')->create());

        Livewire::test(ListWorkstations::class)->assertTableActionHidden('replicate', $desk);
    }

    public function test_the_status_of_many_desks_is_changed_at_once(): void
    {
        $desks = Workstation::factory()->count(3)->create();

        Livewire::test(ListWorkstations::class)
            ->callTableBulkAction('setStatus', $desks, data: ['status' => WorkstationStatus::Offline->value])
            ->assertHasNoTableBulkActionErrors();

        foreach ($desks as $desk) {
            $this->assertSame(WorkstationStatus::Offline, $desk->refresh()->status);
        }
    }

    public function test_a_bulk_vlan_change_skips_desks_at_another_site(): void
    {
        $here = Workstation::factory()->create();
        $elsewhere = Workstation::factory()->for(
            Floor::factory()->for(Building::factory()->create(['site_id' => Site::factory()->create()->id]))
        )->create();
        $vlan = Vlan::factory()->create(['site_id' => $here->floor->building->site_id]);

        Livewire::test(ListWorkstations::class)
            ->callTableBulkAction('setVlan', [$here, $elsewhere], data: ['vlan_id' => $vlan->id]);

        $this->assertSame($vlan->id, $here->refresh()->vlan_id);
        $this->assertNull($elsewhere->refresh()->vlan_id);
    }
}

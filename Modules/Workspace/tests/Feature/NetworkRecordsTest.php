<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Settings\Filament\Admin\Resources\Sites\Pages\ManageSites;
use Modules\Settings\Models\Site;
use Modules\Workspace\Filament\Admin\Resources\Buildings\Pages\ManageBuildings;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\EditFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers\AreasRelationManager;
use Modules\Workspace\Filament\Admin\Resources\Racks\Pages\ManageRacks;
use Modules\Workspace\Filament\Admin\Resources\Switches\Pages\CreateNetworkSwitch;
use Modules\Workspace\Filament\Admin\Resources\Switches\Pages\EditNetworkSwitch;
use Modules\Workspace\Filament\Admin\Resources\Switches\Pages\ListNetworkSwitches;
use Modules\Workspace\Filament\Admin\Resources\Switches\RelationManagers\PortsRelationManager;
use Modules\Workspace\Filament\Admin\Resources\Vlans\Pages\ManageVlans;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Buildings, areas, racks, switches, ports and VLANs. */
class NetworkRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public static function screens(): array
    {
        return [
            'buildings' => ['/admin/buildings', 'floors.view'],
            'switches' => ['/admin/switches', 'network.view'],
            'new switch' => ['/admin/switches/create', 'network.create'],
            'racks' => ['/admin/racks', 'network.view'],
            'vlans' => ['/admin/vlans', 'network.view'],
        ];
    }

    #[DataProvider('screens')]
    public function test_each_screen_renders_and_needs_its_permission(string $url, string $permission): void
    {
        Floor::factory()->create();

        $this->get($url)->assertSuccessful();

        $this->actingAs(User::factory()->withPermissions('workstations.view')->create())
            ->get($url)
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('network.view', 'floors.view', $permission)->create())
            ->get($url)
            ->assertSuccessful();
    }

    public function test_the_network_group_is_in_the_sidebar(): void
    {
        $this->get('/admin')
            ->assertSeeInOrder(['Floor Management', 'Buildings', 'Floor Setup', 'Network', 'Switches', 'Racks', 'VLANs', 'Asset Management']);
    }

    public function test_a_building_is_added_to_a_site(): void
    {
        $site = Site::factory()->create(['name' => 'Alexandria Site']);

        Livewire::test(ManageBuildings::class)
            ->callAction('create', ['site_id' => $site->id, 'name' => 'HQ Tower B', 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('buildings', ['site_id' => $site->id, 'name' => 'HQ Tower B']);
    }

    public function test_a_building_with_floors_and_a_site_with_buildings_cannot_be_deleted(): void
    {
        $floor = Floor::factory()->create();
        $building = $floor->building;
        $emptyBuilding = Building::factory()->create(['site_id' => $building->site_id]);

        Livewire::test(ManageBuildings::class)
            ->assertTableActionHidden('delete', $building)
            ->assertTableActionVisible('delete', $emptyBuilding);

        Livewire::test(ManageSites::class)
            ->assertTableActionHidden('delete', $building->site);
    }

    public function test_areas_are_added_on_the_floor_and_unique_within_it(): void
    {
        $floor = Floor::factory()->create();
        $other = Floor::factory()->create();
        Area::factory()->create(['floor_id' => $other->id, 'name' => 'Zone A']);

        $manager = Livewire::test(AreasRelationManager::class, ['ownerRecord' => $floor, 'pageClass' => EditFloor::class]);

        $manager->callTableAction('create', data: ['name' => 'Zone A'])->assertHasNoTableActionErrors();
        $manager->callTableAction('create', data: ['name' => 'Zone A'])->assertHasTableActionErrors(['name' => 'unique']);

        $this->assertSame(1, $floor->areas()->count());
    }

    public function test_deleting_an_area_keeps_its_desks_on_the_floor(): void
    {
        $area = Area::factory()->create();
        $desk = Workstation::factory()->create(['floor_id' => $area->floor_id, 'area_id' => $area->id]);

        $area->delete();

        $this->assertModelExists($desk);
        $this->assertNull($desk->refresh()->area_id);
    }

    public function test_a_switch_number_is_unique_within_its_building_only(): void
    {
        $tower = Building::factory()->create();
        $annex = Building::factory()->create();
        NetworkSwitch::factory()->create(['building_id' => $tower->id, 'number' => 'SW-01']);

        Livewire::test(CreateNetworkSwitch::class)
            ->fillForm(['building_id' => $annex->id, 'number' => 'SW-01', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateNetworkSwitch::class)
            ->fillForm(['building_id' => $tower->id, 'number' => 'SW-01', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['number' => 'unique']);
    }

    public function test_a_switch_cannot_be_put_in_a_rack_in_another_building(): void
    {
        $tower = Building::factory()->create();
        $foreignRack = Rack::factory()->create(['building_id' => Building::factory()->create()->id]);

        Livewire::test(CreateNetworkSwitch::class)
            ->fillForm(['building_id' => $tower->id, 'rack_id' => $foreignRack->id, 'number' => 'SW-01'])
            ->call('create')
            ->assertHasFormErrors(['rack_id']);
    }

    public function test_a_range_of_ports_is_added_in_one_go_and_existing_ones_are_skipped(): void
    {
        $switch = NetworkSwitch::factory()->create(['port_count' => 48]);
        SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi1/0/3']);

        Livewire::test(PortsRelationManager::class, ['ownerRecord' => $switch, 'pageClass' => EditNetworkSwitch::class])
            ->callTableAction('addPorts', data: ['prefix' => 'Gi1/0/', 'from' => 1, 'to' => 48])
            ->assertHasNoTableActionErrors();

        $this->assertSame(48, $switch->ports()->count());
        $this->assertSame(1, $switch->ports()->where('name', 'Gi1/0/3')->count());
    }

    public function test_a_switch_or_port_with_a_desk_patched_to_it_cannot_be_deleted(): void
    {
        $desk = Workstation::factory()->wired()->create();
        $port = $desk->switchPort;
        $free = SwitchPort::factory()->create(['network_switch_id' => $port->network_switch_id]);

        Livewire::test(ListNetworkSwitches::class)
            ->assertTableActionHidden('delete', $port->networkSwitch);

        Livewire::test(PortsRelationManager::class, ['ownerRecord' => $port->networkSwitch, 'pageClass' => EditNetworkSwitch::class])
            ->assertTableActionHidden('delete', $port)
            ->assertTableActionVisible('delete', $free);
    }

    public function test_a_rack_with_switches_and_a_vlan_in_use_cannot_be_deleted(): void
    {
        $rack = Rack::factory()->create();
        NetworkSwitch::factory()->create(['building_id' => $rack->building_id, 'rack_id' => $rack->id]);
        $desk = Workstation::factory()->wired()->create();

        Livewire::test(ManageRacks::class)->assertTableActionHidden('delete', $rack);
        Livewire::test(ManageVlans::class)->assertTableActionHidden('delete', $desk->vlan);
    }

    public function test_a_vlan_id_is_unique_per_site_and_must_be_a_real_vlan_id(): void
    {
        $site = Site::factory()->create();
        Vlan::factory()->create(['site_id' => $site->id, 'number' => 123]);

        Livewire::test(ManageVlans::class)
            ->callAction('create', ['site_id' => $site->id, 'number' => 123])
            ->assertHasActionErrors(['number' => 'unique']);

        Livewire::test(ManageVlans::class)
            ->callAction('create', ['site_id' => $site->id, 'number' => 5000])
            ->assertHasActionErrors(['number']);

        Livewire::test(ManageVlans::class)
            ->callAction('create', ['site_id' => $site->id, 'number' => 124, 'subnet' => '10.20.30.0/99'])
            ->assertHasActionErrors(['subnet']);

        Livewire::test(ManageVlans::class)
            ->callAction('create', ['site_id' => Site::factory()->create()->id, 'number' => 123, 'subnet' => '10.20.30.0/24'])
            ->assertHasNoActionErrors();
    }

    public function test_viewing_the_network_does_not_allow_changing_it(): void
    {
        $this->actingAs(User::factory()->withPermissions('network.view')->create());
        $rack = Rack::factory()->create();

        Livewire::test(ManageRacks::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $rack)
            ->assertTableActionHidden('delete', $rack);
    }
}

<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Settings\Models\Site;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\EditFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers\WorkstationsRelationManager;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\EditWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class WorkstationDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** The text part of one filled-in sheet, as it arrives from a form. */
    private const TEXT = [
        'workstation_number' => '214',
        'desk_row' => '3',
        'desk_position' => '12',
        'port_split_number' => '24',
        'computer_name' => 'ALX-PC-024',
        'pc_serial' => 'PC00012345',
        'monitor_serial' => 'MON0012345',
        'ip_address' => '10.20.30.40',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'notes' => 'Under the window.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /**
     * A whole sheet for a desk on this floor: an area of the floor, a port on
     * a switch in a rack in its building, a VLAN at its site.
     *
     * @return array<string, mixed>
     */
    private function sheet(Floor $floor): array
    {
        $rack = Rack::factory()->create(['building_id' => $floor->building_id, 'number' => 'RACK-02']);
        $switch = NetworkSwitch::factory()->create(['building_id' => $floor->building_id, 'rack_id' => $rack->id, 'number' => 'SW-02']);

        return [
            'status' => WorkstationStatus::Faulty->value,
            'area_id' => Area::factory()->create(['floor_id' => $floor->id, 'name' => 'Operations Floor'])->id,
            'switch_id' => $switch->id,
            'switch_port_id' => SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi2/0/24'])->id,
            'vlan_id' => Vlan::factory()->create(['site_id' => $floor->building->site_id, 'number' => 123, 'name' => 'Ops'])->id,
            ...self::TEXT,
        ];
    }

    /** @param  array<string, mixed>  $sheet */
    private function assertSaved(Workstation $desk, array $sheet): void
    {
        $desk->refresh();

        foreach ($sheet as $field => $value) {
            if ($field === 'switch_id') {
                $this->assertSame($value, $desk->switchPort?->network_switch_id, 'switch');

                continue;
            }

            $stored = $desk->getAttribute($field);
            $this->assertSame($value, $stored instanceof WorkstationStatus ? $stored->value : $stored, $field);
        }
    }

    // ---- the model -----------------------------------------------------

    public function test_the_whole_record_is_optional_and_a_desk_starts_active(): void
    {
        $floor = Floor::factory()->create();

        $desk = $floor->workstations()->create(['name' => 'A-01'])->fresh();

        $this->assertDatabaseHas('workstations', array_merge(
            ['id' => $desk->id, 'status' => 'active'],
            array_fill_keys([...Workstation::DETAIL_RELATIONS, ...Workstation::DETAIL_COLUMNS], null),
        ));
        $this->assertSame(WorkstationStatus::Active, $desk->status);
        $this->assertFalse($desk->hasDetails());
    }

    public function test_the_old_free_text_patching_columns_are_gone(): void
    {
        foreach (['port', 'firewall', 'site_location', 'zone_number', 'switch_number', 'interface_number'] as $column) {
            $this->assertFalse(Schema::hasColumn('workstations', $column), $column);
        }
    }

    /** Every field counts, so a desk with only one thing filled in still shows up. */
    public function test_any_single_field_makes_a_desk_count_as_detailed(): void
    {
        $floor = Floor::factory()->create();
        $sheet = $this->sheet($floor);

        $this->assertFalse(Workstation::factory()->for($floor)->create()->hasDetails());

        foreach ([...Workstation::DETAIL_RELATIONS, ...Workstation::DETAIL_COLUMNS] as $column) {
            $desk = Workstation::factory()->for($floor)->create([$column => $sheet[$column]]);

            $this->assertTrue($desk->hasDetails(), "{$column} on its own should count as a detail");

            // A port can only be held once, so let the next desk have it.
            $desk->update(['switch_port_id' => null]);
        }
    }

    public function test_the_status_does_not_count_as_a_detail(): void
    {
        $this->assertFalse(Workstation::factory()->status(WorkstationStatus::Faulty)->create()->hasDetails());
    }

    public function test_a_status_is_read_the_way_a_spreadsheet_writes_it(): void
    {
        $this->assertSame(WorkstationStatus::UnderMaintenance, WorkstationStatus::fromLoose('Under Maintenance'));
        $this->assertSame(WorkstationStatus::UnderMaintenance, WorkstationStatus::fromLoose('maintenance'));
        $this->assertSame(WorkstationStatus::Faulty, WorkstationStatus::fromLoose(' FAULTY '));
        $this->assertNull(WorkstationStatus::fromLoose('broken-ish'));
        $this->assertNull(WorkstationStatus::fromLoose(''));
    }

    /**
     * MACs get copied off a switch table, a label, or an ipconfig dump, so they
     * arrive in four different shapes for the same NIC. Normalising on the way
     * in is what makes searching for one work.
     */
    public function test_a_mac_address_is_stored_canonically_however_it_is_typed(): void
    {
        foreach (['aa:bb:cc:dd:ee:ff', 'AA-BB-CC-DD-EE-FF', 'aabb.ccdd.eeff', 'aabbccddeeff'] as $typed) {
            $desk = Workstation::factory()->create(['mac_address' => $typed])->fresh();

            $this->assertSame('AA:BB:CC:DD:EE:FF', $desk->mac_address, "typed as {$typed}");
        }
    }

    public function test_an_empty_mac_address_stays_null(): void
    {
        $this->assertNull(Workstation::factory()->create(['mac_address' => ''])->fresh()->mac_address);
    }

    /** Real labels are alphanumeric. A column that only held digits would not be a record of the equipment. */
    public function test_the_numbered_fields_accept_the_labels_that_are_actually_used(): void
    {
        $desk = Workstation::factory()->create([
            'workstation_number' => '214-B',
            'port_split_number' => 'A/B',
            'desk_row' => '3a',
        ])->fresh();

        $this->assertSame('214-B', $desk->workstation_number);
        $this->assertSame('A/B', $desk->port_split_number);
        $this->assertSame('3a', $desk->desk_row);
    }

    /** The rack is not stored on the desk: it is wherever the desk's switch is. */
    public function test_the_switch_and_rack_come_through_the_port(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create(collect($this->sheet($floor))->except('switch_id')->all());

        $this->assertSame('SW-02', $desk->networkSwitch()->number);
        $this->assertSame('RACK-02', $desk->rack()->number);
        $this->assertSame('SW-02', $desk->detailValue('switch'));
        $this->assertSame('Gi2/0/24', $desk->detailValue('port'));
        $this->assertSame('123 · Ops', $desk->detailValue('vlan'));
    }

    /**
     * The scope runs inside a floor's relationship, so a loose `orWhereNotNull`
     * would escape the floor constraint and drag in other floors' desks.
     */
    public function test_the_details_scope_stays_inside_its_floor(): void
    {
        $floor = Floor::factory()->create();
        $mine = Workstation::factory()->for($floor)->wired()->create();
        Workstation::factory()->for(Floor::factory())->wired()->create();

        $found = $floor->workstations()->withDetails()->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($mine));
    }

    public function test_the_details_scope_can_be_inverted(): void
    {
        $floor = Floor::factory()->create();
        $bare = Workstation::factory()->for($floor)->create();
        Workstation::factory()->for($floor)->wired()->create();

        $found = $floor->workstations()->withDetails(false)->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($bare));
    }

    // ---- the plan's details modal --------------------------------------

    public function test_clicking_a_desk_on_the_plan_opens_it_filled_in(): void
    {
        $floor = Floor::factory()->create();
        $sheet = $this->sheet($floor);
        $desk = Workstation::factory()->for($floor)->placed()->create(collect($sheet)->except('switch_id')->all());

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->mountAction('deskDetails', ['workstation' => $desk->getKey()])
            // The status field holds the enum itself, not its stored string.
            ->assertActionDataSet([...$sheet, 'status' => WorkstationStatus::Faulty]);
    }

    public function test_the_whole_sheet_can_be_saved_from_the_plan(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();
        $sheet = $this->sheet($floor);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: $sheet)
            ->assertHasNoActionErrors();

        $this->assertSaved($desk, $sheet);
    }

    /** Saving the desk that already holds a port must not trip over its own port. */
    public function test_a_desk_can_be_saved_again_with_the_port_it_already_holds(): void
    {
        $floor = Floor::factory()->create();
        $sheet = $this->sheet($floor);
        $desk = Workstation::factory()->for($floor)->placed()->create(collect($sheet)->except('switch_id')->all());

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: [...$sheet, 'notes' => 'Moved the chair.'])
            ->assertHasNoActionErrors();

        $this->assertSame('Moved the chair.', $desk->refresh()->notes);
    }

    public function test_details_can_be_cleared_again(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->wired()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: [
                ...array_fill_keys([...Workstation::DETAIL_RELATIONS, ...Workstation::DETAIL_COLUMNS], null),
                'switch_id' => null,
            ])
            ->assertHasNoActionErrors();

        $desk->refresh();

        $this->assertNull($desk->switch_port_id);
        $this->assertNull($desk->mac_address);
        $this->assertFalse($desk->hasDetails());
    }

    /**
     * A field cleared to spaces and a field never filled in have to end up as
     * the same thing, or the "has details" filter and the ring on the plan
     * start disagreeing about the same desk.
     */
    public function test_whitespace_is_stored_as_nothing(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: [
                'desk_row' => '   ',
                'computer_name' => '  ALX-PC-024  ',
            ])
            ->assertHasNoActionErrors();

        $desk->refresh();

        $this->assertNull($desk->desk_row);
        $this->assertSame('ALX-PC-024', $desk->computer_name);
    }

    public function test_a_mac_or_ip_address_that_is_not_one_is_rejected(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        foreach (['not-a-mac', 'AA:BB:CC:DD:EE', 'GG:BB:CC:DD:EE:FF'] as $rubbish) {
            Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
                ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: ['mac_address' => $rubbish])
                ->assertHasActionErrors(['mac_address']);
        }

        foreach (['10.20.30', '999.1.1.1', 'server-01'] as $rubbish) {
            Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
                ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: ['ip_address' => $rubbish])
                ->assertHasActionErrors(['ip_address']);
        }

        $this->assertNull($desk->refresh()->mac_address);
        $this->assertNull($desk->ip_address);
    }

    /** One port, one desk: two desks on a port is a patching mistake. */
    public function test_a_port_another_desk_holds_is_refused(): void
    {
        $floor = Floor::factory()->create();
        $sheet = $this->sheet($floor);
        Workstation::factory()->for($floor)->create(['switch_port_id' => $sheet['switch_port_id']]);
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: [
                'switch_id' => $sheet['switch_id'],
                'switch_port_id' => $sheet['switch_port_id'],
            ])
            ->assertHasActionErrors(['switch_port_id' => 'unique']);

        $this->assertNull($desk->refresh()->switch_port_id);
    }

    /**
     * Every id in the form arrives from the browser, so a crafted one must not
     * attach a desk to an area of another floor, a switch in another building
     * or a VLAN at another site.
     */
    public function test_choices_from_somewhere_else_are_refused(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        $otherFloor = Floor::factory()->create();
        $otherBuilding = Building::factory()->create();
        $otherSite = Site::factory()->create();

        $foreignArea = Area::factory()->create(['floor_id' => $otherFloor->id]);
        $foreignSwitch = NetworkSwitch::factory()->create(['building_id' => $otherBuilding->id]);
        $foreignPort = SwitchPort::factory()->create(['network_switch_id' => $foreignSwitch->id]);
        $foreignVlan = Vlan::factory()->create(['site_id' => $otherSite->id]);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: [
                'area_id' => $foreignArea->id,
                'switch_id' => $foreignSwitch->id,
                'switch_port_id' => $foreignPort->id,
                'vlan_id' => $foreignVlan->id,
            ])
            ->assertHasActionErrors(['area_id', 'switch_port_id', 'vlan_id']);

        $this->assertFalse($desk->refresh()->hasDetails());
    }

    /**
     * The desk id comes from the browser, so the modal must not become a way to
     * edit a desk on a floor you are not looking at.
     */
    public function test_the_modal_refuses_a_desk_from_another_floor(): void
    {
        $floor = Floor::factory()->create();
        $elsewhere = Workstation::factory()->for(Floor::factory())->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $elsewhere->getKey()], data: ['computer_name' => 'X']);
    }

    /**
     * The plan sits behind wire:ignore, so a save has to report back or the pin
     * keeps showing the desk as having nothing on it until a full reload.
     */
    public function test_saving_tells_the_plan_which_desk_changed(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: ['computer_name' => 'ALX-PC-024'])
            ->assertDispatched('desk-details-saved', workstation: $desk->getKey(), hasDetails: true);
    }

    public function test_the_plan_marks_which_desks_have_details(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-01']);
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-02', 'computer_name' => 'ALX-PC-002']);

        $desks = collect(Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->instance()
            ->mapConfig()['desks'])->sortBy('name')->values();

        $this->assertFalse($desks[0]['details']);
        $this->assertTrue($desks[1]['details']);
    }

    // ---- the list ------------------------------------------------------

    public function test_every_field_is_listed(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create(collect($this->sheet($floor))->except('switch_id')->all());

        $list = Livewire::test(ListWorkstations::class);

        foreach (collect(self::TEXT)->except('notes') as $column => $value) {
            $list->assertTableColumnStateSet($column, $value, $desk);
        }

        $list->assertTableColumnStateSet('area.name', 'Operations Floor', $desk)
            ->assertTableColumnStateSet('switchPort.networkSwitch.number', 'SW-02', $desk)
            ->assertTableColumnStateSet('switchPort.name', 'Gi2/0/24', $desk)
            ->assertTableColumnStateSet('switchPort.networkSwitch.rack.number', 'RACK-02', $desk)
            ->assertTableColumnStateSet('vlan.number', 123, $desk)
            ->assertTableColumnStateSet('status', WorkstationStatus::Faulty, $desk);
    }

    public function test_the_list_filters_by_status_switch_rack_and_vlan(): void
    {
        $floor = Floor::factory()->create();
        $sheet = $this->sheet($floor);
        $patched = Workstation::factory()->for($floor)->create(collect($sheet)->except('switch_id')->all());
        $other = Workstation::factory()->for($floor)->create();

        $rack = Rack::query()->where('number', 'RACK-02')->firstOrFail();

        Livewire::test(ListWorkstations::class)
            ->filterTable('status', [WorkstationStatus::Faulty->value])
            ->assertCanSeeTableRecords([$patched])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('switch', $sheet['switch_id'])
            ->assertCanSeeTableRecords([$patched])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('rack', $rack->id)
            ->assertCanSeeTableRecords([$patched])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('vlan_id', $sheet['vlan_id'])
            ->assertCanSeeTableRecords([$patched])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_list_is_searchable_by_pc_name_workstation_number_and_switch_port(): void
    {
        $floor = Floor::factory()->create();
        $patched = Workstation::factory()->for($floor)->create(collect($this->sheet($floor))->except('switch_id')->all());
        $other = Workstation::factory()->for($floor)->create();

        foreach (['ALX-PC-024', '214', 'Gi2/0/24', 'SW-02', '10.20.30.40', 'AA:BB:CC:DD:EE:FF'] as $term) {
            Livewire::test(ListWorkstations::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$patched])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_the_has_details_filter_splits_the_list(): void
    {
        $wired = Workstation::factory()->wired()->create();
        $bare = Workstation::factory()->create();

        Livewire::test(ListWorkstations::class)
            ->filterTable('details', true)
            ->assertCanSeeTableRecords([$wired])
            ->assertCanNotSeeTableRecords([$bare])
            ->filterTable('details', false)
            ->assertCanSeeTableRecords([$bare])
            ->assertCanNotSeeTableRecords([$wired]);
    }

    // ---- the other two places a desk is edited -------------------------

    public function test_the_details_are_on_the_floors_workstations_tab(): void
    {
        $floor = Floor::factory()->create();
        $sheet = $this->sheet($floor);

        Livewire::test(WorkstationsRelationManager::class, [
            'ownerRecord' => $floor,
            'pageClass' => EditFloor::class,
        ])
            ->callTableAction('create', data: ['name' => 'WS-024', ...$sheet])
            ->assertHasNoTableActionErrors();

        $this->assertSaved($floor->workstations()->where('name', 'WS-024')->firstOrFail(), $sheet);
    }

    public function test_the_details_are_on_the_cross_floor_resource(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create();
        $sheet = $this->sheet($floor);

        Livewire::test(EditWorkstation::class, ['record' => $desk->getKey()])
            ->fillForm($sheet)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSaved($desk, $sheet);
    }

    /** Switches belong to a building: moving a desk to another building cannot keep its port. */
    public function test_moving_a_desk_to_another_building_lets_go_of_its_patching(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create(collect($this->sheet($floor))->except('switch_id')->all());
        $elsewhere = Floor::factory()->for(Building::factory()->create(['site_id' => $floor->building->site_id]))->create();

        Livewire::test(EditWorkstation::class, ['record' => $desk->getKey()])
            ->fillForm(['floor_id' => $elsewhere->getKey()])
            ->assertSchemaStateSet(['area_id' => null, 'switch_port_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $desk->refresh();
        $this->assertSame($elsewhere->id, $desk->floor_id);
        $this->assertNull($desk->switch_port_id);
        // Same site, so the VLAN still applies.
        $this->assertNotNull($desk->vlan_id);
    }

    // ---- the public site -----------------------------------------------

    /**
     * The patching record is deliberately public, on an unauthenticated site.
     *
     * That was an explicit decision rather than an oversight, and this test is
     * what makes it a decision: the day somebody wants it private again, this
     * fails and says so out loud rather than the data quietly staying visible.
     */
    public function test_the_public_site_carries_the_patching_record_on_purpose(): void
    {
        auth()->logout();

        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-01', ...collect($this->sheet($floor))->except('switch_id')->all()]);

        $response = $this->get(route('building.floor', $floor))->assertSuccessful();

        foreach (['A-01', 'Operations Floor', '214', 'SW-02', 'Gi2/0/24', 'ALX-PC-024', 'AA:BB:CC:DD:EE:FF', 'Under the window.'] as $value) {
            $response->assertSee($value, escape: false);
        }
    }

    /**
     * Fields added since the public site was opened up stay admin-only until
     * somebody decides otherwise. This is the test that will fail if one of
     * them reaches a page with no login.
     */
    public function test_the_fields_added_since_stay_off_the_public_site(): void
    {
        auth()->logout();

        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-01', ...collect($this->sheet($floor))->except('switch_id')->all()]);

        $response = $this->get(route('building.floor', $floor))->assertSuccessful();

        foreach (['10.20.30.40', 'PC00012345', 'MON0012345', 'RACK-02', 'Ops', 'IP Address', 'VLAN', 'Serial', 'Faulty'] as $value) {
            $response->assertDontSee($value, escape: false);
        }
    }
}

<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\EditFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers\WorkstationsRelationManager;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\EditWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class WorkstationDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** One filled-in patching sheet, as it arrives from a form. */
    private const SHEET = [
        'site_location' => 'HQ Tower B',
        'zone_number' => 'Z3',
        'workstation_number' => '214',
        'port_split_number' => 'A',
        'switch_number' => 'SW-03',
        'interface_number' => 'Gi1/0/24',
        'computer_name' => 'HQ-WS-0214',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'notes' => 'Under the window.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    // ---- the model -----------------------------------------------------

    public function test_the_whole_record_is_optional(): void
    {
        $floor = Floor::factory()->create();

        $desk = $floor->workstations()->create(['name' => 'A-01']);

        $this->assertDatabaseHas('workstations', array_merge(
            ['id' => $desk->id],
            array_fill_keys(Workstation::DETAIL_COLUMNS, null),
        ));
        $this->assertFalse($desk->hasDetails());
    }

    public function test_the_old_port_and_firewall_columns_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('workstations', 'port'));
        $this->assertFalse(Schema::hasColumn('workstations', 'firewall'));
    }

    /** Every column counts, so a desk with only one thing filled in still shows up. */
    public function test_any_single_field_makes_a_desk_count_as_detailed(): void
    {
        $this->assertFalse(Workstation::factory()->create()->hasDetails());

        foreach (Workstation::DETAIL_COLUMNS as $column) {
            $value = $column === 'mac_address' ? 'AA:BB:CC:DD:EE:FF' : 'x';

            $this->assertTrue(
                Workstation::factory()->create([$column => $value])->hasDetails(),
                "{$column} on its own should count as a detail",
            );
        }
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
        $desk = Workstation::factory()->create(['mac_address' => ''])->fresh();

        $this->assertNull($desk->mac_address);
    }

    /**
     * Real switch and interface labels are alphanumeric. A column that could
     * only hold digits would not be a record of what is on the equipment.
     */
    public function test_the_numbered_fields_accept_the_labels_that_are_actually_used(): void
    {
        $desk = Workstation::factory()->create([
            'interface_number' => 'Gi1/0/24',
            'switch_number' => 'SW-03',
            'port_split_number' => 'A/B',
            'zone_number' => 'Z-3a',
        ])->fresh();

        $this->assertSame('Gi1/0/24', $desk->interface_number);
        $this->assertSame('SW-03', $desk->switch_number);
        $this->assertSame('A/B', $desk->port_split_number);
        $this->assertSame('Z-3a', $desk->zone_number);
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
        $desk = Workstation::factory()->for($floor)->placed()->create(self::SHEET);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->mountAction('deskDetails', ['workstation' => $desk->getKey()])
            ->assertActionDataSet(self::SHEET);
    }

    public function test_the_whole_sheet_can_be_saved_from_the_plan(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: self::SHEET)
            ->assertHasNoActionErrors();

        $desk->refresh();

        foreach (self::SHEET as $column => $value) {
            $this->assertSame($value, $desk->{$column}, $column);
        }
    }

    public function test_saving_nothing_leaves_the_record_empty(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: array_fill_keys(
                Workstation::DETAIL_COLUMNS,
                null,
            ))
            ->assertHasNoActionErrors();

        $this->assertFalse($desk->refresh()->hasDetails());
    }

    public function test_details_can_be_cleared_again(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->wired()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: array_fill_keys(
                Workstation::DETAIL_COLUMNS,
                null,
            ));

        $desk->refresh();

        $this->assertNull($desk->switch_number);
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
                'site_location' => '   ',
                'switch_number' => '  SW-03  ',
            ])
            ->assertHasNoActionErrors();

        $desk->refresh();

        $this->assertNull($desk->site_location);
        $this->assertSame('SW-03', $desk->switch_number);
    }

    public function test_a_mac_address_that_is_not_one_is_rejected(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        foreach (['not-a-mac', 'AA:BB:CC:DD:EE', 'GG:BB:CC:DD:EE:FF'] as $rubbish) {
            Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
                ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: ['mac_address' => $rubbish])
                ->assertHasActionErrors(['mac_address']);
        }

        $this->assertNull($desk->refresh()->mac_address);
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
            ->callAction('deskDetails', arguments: ['workstation' => $elsewhere->getKey()], data: ['switch_number' => 'SW-01']);
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
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: ['switch_number' => 'SW-03'])
            ->assertDispatched('desk-details-saved', workstation: $desk->getKey(), hasDetails: true);
    }

    public function test_the_plan_marks_which_desks_have_details(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-01']);
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-02', 'switch_number' => 'SW-03']);

        $desks = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->instance()
            ->planDesks();

        $this->assertFalse($desks[0]['details']);
        $this->assertTrue($desks[1]['details']);
    }

    // ---- the list ------------------------------------------------------

    public function test_every_field_is_listed(): void
    {
        $desk = Workstation::factory()->create(self::SHEET);

        $list = Livewire::test(ListWorkstations::class);

        foreach (Workstation::DETAIL_COLUMNS as $column) {
            if ($column === 'notes') {
                continue; // Notes are free text; they live in the form, not the table.
            }

            $list->assertTableColumnFormattedStateSet($column, self::SHEET[$column], $desk);
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

        Livewire::test(WorkstationsRelationManager::class, [
            'ownerRecord' => $floor,
            'pageClass' => EditFloor::class,
        ])
            ->callTableAction('create', data: array_merge(['name' => 'A-01'], self::SHEET))
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('workstations', array_merge(
            ['floor_id' => $floor->id, 'name' => 'A-01'],
            self::SHEET,
        ));
    }

    public function test_the_details_are_on_the_cross_floor_resource(): void
    {
        $desk = Workstation::factory()->create();

        Livewire::test(EditWorkstation::class, ['record' => $desk->getKey()])
            ->fillForm(self::SHEET)
            ->call('save')
            ->assertHasNoFormErrors();

        $desk->refresh();

        foreach (self::SHEET as $column => $value) {
            $this->assertSame($value, $desk->{$column}, $column);
        }
    }

    /**
     * The whole record is deliberately public, on an unauthenticated site.
     *
     * That was an explicit decision rather than an oversight, and this test is
     * what makes it a decision: the day somebody wants the patching record
     * private again, this fails and says so out loud rather than the data
     * quietly staying visible.
     */
    public function test_the_public_site_carries_the_whole_record_on_purpose(): void
    {
        auth()->logout();

        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create(array_merge(
            ['name' => 'A-01'],
            self::SHEET,
        ));

        $response = $this->get(route('building.floor', $floor))->assertSuccessful();

        $response->assertSee('A-01');

        foreach (self::SHEET as $value) {
            $response->assertSee($value, escape: false);
        }
    }
}

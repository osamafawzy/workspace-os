<?php

namespace Modules\Access\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * The plan calls its write methods straight from the browser, so hiding the
 * tools from somebody who may only look is not enough on its own. These prove
 * the server refuses the writes too.
 */
class FloorPlanPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected Floor $floor;

    protected Workstation $desk;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->floor = Floor::factory()->create();
        $this->desk = Workstation::factory()->for($this->floor)->create();
    }

    public function test_viewing_floors_is_enough_to_see_the_plan(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get("/admin/floors/{$this->floor->getKey()}/plan")
            ->assertSuccessful()
            ->assertSee('floorMap(', escape: false);
    }

    public function test_the_plan_is_closed_without_view_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view')->create())
            ->get("/admin/floors/{$this->floor->getKey()}/plan")
            ->assertForbidden();
    }

    /** @return list<array<string, mixed>> the desk placed at 12 m, 8 m */
    protected function deskOnTheMap(): array
    {
        return [[
            'id' => null, 'type' => 'workstation', 'workstation_id' => $this->desk->getKey(),
            'x' => 12, 'y' => 8, 'z' => 0, 'width' => 1.4, 'depth' => 1.5, 'height' => 0.75, 'rotation' => 0,
        ]];
    }

    public function test_a_viewer_cannot_change_the_map_even_by_calling_the_method(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->call('saveMap', 0, $this->deskOnTheMap())
            ->assertForbidden();

        $this->assertFalse($this->desk->refresh()->isPlaced());
    }

    public function test_the_arrange_permission_lets_the_map_be_saved(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view', 'floors.arrange')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->call('saveMap', 0, $this->deskOnTheMap())
            ->assertSuccessful();

        $this->assertSame(12.0, $this->desk->refresh()->mapObject->x);
    }

    public function test_creating_a_desk_from_the_map_needs_both_arrange_and_create(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view', 'floors.arrange')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->call('createDesk', 'WS-NEW')
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('floors.view', 'floors.arrange', 'workstations.create')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->call('createDesk', 'WS-NEW');

        $this->assertDatabaseHas('workstations', ['name' => 'WS-NEW']);
    }

    public function test_a_viewer_reads_desk_details_but_cannot_save_them(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->mountAction('deskDetails', ['workstation' => $this->desk->getKey()])
            ->assertActionMounted('deskDetails')
            ->setActionData(['computer_name' => 'PC-HIJACK'])
            ->callMountedAction();

        $this->assertNull($this->desk->refresh()->computer_name);
    }

    public function test_add_many_needs_the_create_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view', 'floors.arrange')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->assertActionHidden('addManyWorkstations');
    }
}

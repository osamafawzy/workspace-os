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
            ->assertSee('You can look at this plan but not rearrange it');
    }

    public function test_the_plan_is_closed_without_view_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view')->create())
            ->get("/admin/floors/{$this->floor->getKey()}/plan")
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_move_a_desk_even_by_calling_the_method(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->call('place', $this->desk->getKey(), 40, 40)
            ->assertForbidden();

        $this->assertNull($this->desk->refresh()->position_x);
    }

    public function test_a_viewer_cannot_fill_arrange_or_clear(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        foreach ([['fillArea', 0, 0, 50, 50, 1, 1], ['autoArrange'], ['clearPlacements']] as $call) {
            Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
                ->call(...$call)
                ->assertForbidden();
        }

        $this->assertNull($this->desk->refresh()->position_x);
    }

    public function test_the_arrange_permission_lets_desks_move(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view', 'floors.arrange')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->floor->getKey()])
            ->call('place', $this->desk->getKey(), 40, 40)
            ->assertSuccessful();

        $this->assertEquals(40, $this->desk->refresh()->position_x);
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

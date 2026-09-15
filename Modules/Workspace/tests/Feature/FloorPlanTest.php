<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class FloorPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_plan_page_renders(): void
    {
        $floor = Floor::factory()->create(['name' => 'First Floor']);
        Workstation::factory()->for($floor)->create(['name' => 'A-01']);

        $this->get("/admin/floors/{$floor->getKey()}/plan")
            ->assertSuccessful()
            ->assertSee('First Floor')
            // The interaction is entirely client side, so "the page rendered"
            // has to mean the Alpine component and its desk data actually
            // reached the browser — not just that a 200 came back.
            ->assertSee('workspaceFloorPlan', escape: false)
            ->assertSee('ws-plan__viewport', escape: false)
            ->assertSee('A-01');
    }

    public function test_the_plan_page_is_behind_the_login(): void
    {
        $floor = Floor::factory()->create();

        auth()->logout();

        $this->get("/admin/floors/{$floor->getKey()}/plan")->assertRedirect('/admin/login');
    }

    public function test_it_hands_the_browser_every_desk_with_its_coordinates(): void
    {
        $floor = Floor::factory()->create();
        $placed = Workstation::factory()->for($floor)->placed(25.0, 75.0)->create(['name' => 'A-01']);
        $loose = Workstation::factory()->for($floor)->create(['name' => 'A-02']);

        $desks = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->instance()
            ->planDesks();

        $this->assertSame([
            ['id' => $placed->id, 'name' => 'A-01', 'x' => 25.0, 'y' => 75.0, 'details' => false],
            ['id' => $loose->id, 'name' => 'A-02', 'x' => null, 'y' => null, 'details' => false],
        ], $desks);
    }

    public function test_a_desk_can_be_placed(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('place', $desk->getKey(), 33.33, 66.67);

        $desk->refresh();

        $this->assertSame(33.33, $desk->position_x);
        $this->assertSame(66.67, $desk->position_y);
    }

    /**
     * The browser clamps as it drags, but the coordinate arriving here is a
     * number off the wire and nothing stops it being 4000.
     */
    public function test_coordinates_are_clamped_to_the_plan(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('place', $desk->getKey(), -40.0, 4000.0);

        $desk->refresh();

        $this->assertSame(0.0, $desk->position_x);
        $this->assertSame(100.0, $desk->position_y);
    }

    public function test_a_desk_can_be_taken_off_the_plan(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('unplace', $desk->getKey());

        $desk->refresh();

        $this->assertNull($desk->position_x);
        $this->assertNull($desk->position_y);
        $this->assertDatabaseHas('workstations', ['id' => $desk->id]);
    }

    /**
     * The page is reached by floor, and the desk id arrives from the browser.
     * A desk on another floor must not be movable from here.
     */
    public function test_it_refuses_to_move_a_desk_belonging_to_another_floor(): void
    {
        $floor = Floor::factory()->create();
        $elsewhere = Workstation::factory()->for(Floor::factory())->create();

        $page = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()]);

        // Scoped through the floor's own relationship, so the desk is simply
        // not found — which surfaces as a 404 over HTTP, not a silent write.
        try {
            $page->call('place', $elsewhere->getKey(), 10.0, 10.0);
            $this->fail('Expected the desk on another floor to be unreachable.');
        } catch (ModelNotFoundException) {
            // Expected.
        }

        $this->assertNull($elsewhere->refresh()->position_x);
        $this->assertNull($elsewhere->position_y);
    }

    public function test_auto_arrange_places_every_loose_desk_inside_the_plan(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(7)->for($floor)->create();

        $page = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()]);
        $page->call('autoArrange');

        // The browser refreshes its own list from what the call hands back, so
        // the return value is the contract, not just a side effect.
        $desks = $page->instance()->planDesks();

        $this->assertCount(7, $desks);

        foreach ($desks as $desk) {
            $this->assertNotNull($desk['x']);
            $this->assertNotNull($desk['y']);
            $this->assertGreaterThanOrEqual(0, $desk['x']);
            $this->assertLessThanOrEqual(100, $desk['x']);
            $this->assertGreaterThanOrEqual(0, $desk['y']);
            $this->assertLessThanOrEqual(100, $desk['y']);
        }

        $this->assertSame(0, $floor->workstations()->unplaced()->count());
    }

    public function test_auto_arrange_leaves_desks_that_are_already_placed_alone(): void
    {
        $floor = Floor::factory()->create();
        $settled = Workstation::factory()->for($floor)->placed(12.0, 88.0)->create();
        Workstation::factory()->count(3)->for($floor)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])->call('autoArrange');

        $settled->refresh();

        $this->assertSame(12.0, $settled->position_x);
        $this->assertSame(88.0, $settled->position_y);
    }

    /**
     * `unplaced()` mixes an OR into whatever query it lands in. Without its
     * own bracket that OR escapes the floor constraint, and auto-arranging one
     * floor silently rearranges every other floor's loose desks too.
     */
    public function test_auto_arrange_does_not_touch_another_floor(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(2)->for($floor)->create();

        $otherFloor = Floor::factory()->create();
        $untouched = Workstation::factory()->for($otherFloor)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])->call('autoArrange');

        $this->assertNull($untouched->refresh()->position_x);
        $this->assertNull($untouched->position_y);
    }

    public function test_clearing_the_plan_empties_the_coordinates_but_keeps_the_desks(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(3)->for($floor)->placed()->create();

        $page = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()]);
        $page->call('clearPlacements');

        $desks = $page->instance()->planDesks();

        $this->assertCount(3, $desks);
        $this->assertSame([null], array_unique(array_column($desks, 'x')));
        $this->assertSame(3, $floor->workstations()->count());
        $this->assertSame(0, $floor->workstations()->placed()->count());
    }

    public function test_clearing_one_floor_leaves_another_floors_placements_intact(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create();

        $otherFloor = Floor::factory()->create();
        $untouched = Workstation::factory()->for($otherFloor)->placed(40.0, 60.0)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])->call('clearPlacements');

        $this->assertSame(40.0, $untouched->refresh()->position_x);
    }

    public function test_desks_can_be_added_in_bulk_from_the_plan_page(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('addManyWorkstations', data: [
                'prefix' => 'C-',
                'start_at' => 1,
                'count' => 120,
                'pad' => 3,
                'arrange' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(120, $floor->workstations()->count());
        // "Place them on the plan straight away" was on, so nothing should be
        // left sitting in the tray.
        $this->assertSame(0, $floor->workstations()->unplaced()->count());
        $this->assertDatabaseHas('workstations', ['floor_id' => $floor->id, 'name' => 'C-120']);
    }

    public function test_bulk_added_desks_stay_off_the_plan_when_asked(): void
    {
        $floor = Floor::factory()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('addManyWorkstations', data: [
                'prefix' => 'D-',
                'start_at' => 1,
                'count' => 5,
                'pad' => 2,
                'arrange' => false,
            ]);

        $this->assertSame(5, $floor->workstations()->unplaced()->count());
    }

    public function test_the_plan_uses_the_uploaded_drawing_when_there_is_one(): void
    {
        $bare = Floor::factory()->create();
        $drawn = Floor::factory()->create(['plan_path' => 'floor-plans/first.png']);

        $this->assertNull(
            Livewire::test(FloorPlan::class, ['record' => $bare->getKey()])->instance()->planImageUrl(),
        );

        $this->assertStringContainsString(
            'floor-plans/first.png',
            Livewire::test(FloorPlan::class, ['record' => $drawn->getKey()])->instance()->planImageUrl(),
        );
    }

    /** A desk is a computer, not a dot. */
    public function test_each_desk_is_drawn_as_a_computer(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->assertSee('ws-plan__pin-icon', escape: false)
            ->assertSee('--ws-monitor', escape: false);
    }

    /**
     * The window onto the plan is the shape of the drawing in it. Framing a
     * 1.4 drawing in a 1.5 window letterboxes it, and a pin at 50%, 50% then
     * sits half a metre from the desk it is marking.
     */
    public function test_the_plan_is_framed_at_the_shape_of_the_drawing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'floor-plans',
            UploadedFile::fake()->image('plan.png', 400, 200),
            'plan.png',
        );

        $floor = Floor::factory()->create([
            'width_m' => 60,
            'depth_m' => 40,
            'plan_path' => 'floor-plans/plan.png',
        ]);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->assertSee('aspect-ratio: 2;', escape: false)
            ->assertDontSee('aspect-ratio: 1.5;', escape: false);
    }
}

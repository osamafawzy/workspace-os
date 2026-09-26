<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * Filling an area of the floor with desks, on the server.
 *
 * In the editor the same thing happens in the browser, as an undoable draft;
 * this is the server version the seeder and "Add many" use. A drawing shows
 * desks in banks, and a bank is a rectangle: drawing a box round one and saying
 * how many go across and down replaces two hundred drags.
 */
class FillAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /** @return array<int, array{0: float, 1: float}|null> as percentages of the floor, in name order */
    private function positions(Floor $floor): array
    {
        return $floor->workstations()
            ->with(['mapObject', 'floor'])
            ->orderBy('name')
            ->get()
            ->map(fn (Workstation $desk): ?array => $desk->planPosition())
            ->all();
    }

    private function desks(Floor $floor, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $floor->workstations()->create(['name' => 'A-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
    }

    public function test_desks_spread_to_the_corners_of_the_box(): void
    {
        $floor = Floor::factory()->create();
        $this->desks($floor, 4);

        app(ArrangeWorkstations::class)->fill($floor, [10, 20, 30, 40], 2, 2);

        $this->assertSame([[10.0, 20.0], [30.0, 20.0], [10.0, 40.0], [30.0, 40.0]], $this->positions($floor));
    }

    public function test_desks_come_off_the_tray_in_name_order(): void
    {
        $floor = Floor::factory()->create();
        $this->desks($floor, 5);

        $placed = app(ArrangeWorkstations::class)->fill($floor, [0, 0, 50, 50], 3, 1);

        $this->assertSame(['A-01', 'A-02', 'A-03'], $placed->pluck('name')->all());
        $this->assertSame(2, $floor->workstations()->unplaced()->count());
    }

    public function test_a_desk_already_on_the_map_is_left_where_it_is(): void
    {
        $floor = Floor::factory()->create();
        $settled = Workstation::factory()->for($floor)->placed(5.0, 5.0)->create(['name' => 'A-00']);
        $this->desks($floor, 2);

        app(ArrangeWorkstations::class)->fill($floor, [50, 50, 90, 90], 2, 1);

        $this->assertSame([5.0, 5.0], $settled->refresh()->planPosition());
        $this->assertSame(3, $floor->workstations()->placed()->count());
    }

    public function test_filling_cannot_reach_another_floors_desks(): void
    {
        $floor = Floor::factory()->create();
        $elsewhere = Workstation::factory()->for(Floor::factory())->create();
        $this->desks($floor, 1);

        app(ArrangeWorkstations::class)->fill($floor, [0, 0, 100, 100], 10, 10);

        $this->assertFalse($elsewhere->refresh()->isPlaced());
    }

    public function test_asking_for_more_desks_than_the_tray_holds_places_what_it_has(): void
    {
        $floor = Floor::factory()->create();
        $this->desks($floor, 3);

        $placed = app(ArrangeWorkstations::class)->fill($floor, [0, 0, 100, 100], 5, 5);

        $this->assertCount(3, $placed);
        $this->assertSame(3, $floor->workstations()->placed()->count());
    }

    public function test_placements_are_stored_in_metres_on_the_floor_map(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);
        $this->desks($floor, 1);

        app(ArrangeWorkstations::class)->fill($floor, [50, 50, 50, 50], 1, 1);

        $object = $floor->mapObjects()->firstOrFail();

        $this->assertSame('workstation', $object->type);
        $this->assertSame(30.0, $object->x);
        $this->assertSame(20.0, $object->y);
        $this->assertSame(1, $floor->refresh()->map_revision);
    }

    // ---- setting the floor's size from its drawing -------------------------

    public function test_the_floor_size_can_be_taken_from_the_drawing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'floor-plans',
            UploadedFile::fake()->image('plan.png', 1200, 800),
            'plan.png',
        );

        $floor = Floor::factory()->create([
            'width_m' => 24,
            'depth_m' => 16,
            'plan_path' => 'floor-plans/plan.png',
        ]);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('matchToDrawing', data: ['width_m' => 70])
            ->assertHasNoActionErrors();

        // The drawing is 1.5 wide for its height, so 70 m across is 46.67 deep.
        $this->assertSame(70.0, $floor->refresh()->width_m);
        $this->assertSame(46.67, $floor->depth_m);
    }

    /** Nothing to match against, so nothing to offer. */
    public function test_the_size_action_is_hidden_without_a_drawing(): void
    {
        $floor = Floor::factory()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->assertActionHidden('matchToDrawing');
    }
}

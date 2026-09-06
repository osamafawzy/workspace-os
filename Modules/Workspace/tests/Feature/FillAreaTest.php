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
 * Filling an area of the plan.
 *
 * A drawing shows desks in banks, and a bank is a rectangle. Drawing a box
 * round one and saying how many desks go across and down is the difference
 * between an uploaded plan being usable and being a picture you then have to
 * drag two hundred desks onto one at a time.
 */
class FillAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    /** @return array<int, array{0: float|null, 1: float|null}> */
    private function coordinates(Floor $floor): array
    {
        return $floor->workstations()
            ->orderBy('name')
            ->get()
            ->map(fn (Workstation $desk): array => [$desk->position_x, $desk->position_y])
            ->all();
    }

    public function test_desks_spread_to_the_corners_of_the_box(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(6)->for($floor)->sequence(
            ['name' => 'A-1'], ['name' => 'A-2'], ['name' => 'A-3'],
            ['name' => 'A-4'], ['name' => 'A-5'], ['name' => 'A-6'],
        )->create();

        app(ArrangeWorkstations::class)->fill($floor, [20.0, 40.0, 80.0, 60.0], 3, 2);

        // The box was drawn round a bank of desks, so the outermost desks sit
        // on its edges rather than inset from them.
        $this->assertSame([
            [20.0, 40.0], [50.0, 40.0], [80.0, 40.0],
            [20.0, 60.0], [50.0, 60.0], [80.0, 60.0],
        ], $this->coordinates($floor));
    }

    /** A zone should hold a contiguous run, not a scatter of leftovers. */
    public function test_desks_come_off_the_tray_in_name_order(): void
    {
        $floor = Floor::factory()->create();

        foreach (['A-03', 'A-01', 'A-04', 'A-02'] as $name) {
            Workstation::factory()->for($floor)->create(['name' => $name]);
        }

        $placed = app(ArrangeWorkstations::class)->fill($floor, [0.0, 0.0, 100.0, 0.0], 2, 1);

        $this->assertSame(['A-01', 'A-02'], $placed->pluck('name')->all());
        $this->assertSame(['A-03', 'A-04'], $floor->workstations()->unplaced()->orderBy('name')->pluck('name')->all());
    }

    public function test_a_desk_already_on_the_plan_is_left_where_it_is(): void
    {
        $floor = Floor::factory()->create();
        $settled = Workstation::factory()->for($floor)->placed(11.0, 22.0)->create(['name' => 'A-01']);
        Workstation::factory()->for($floor)->create(['name' => 'A-02']);

        app(ArrangeWorkstations::class)->fill($floor, [50.0, 50.0, 60.0, 60.0], 4, 4);

        $this->assertSame(11.0, $settled->refresh()->position_x);
        $this->assertSame(22.0, $settled->position_y);
    }

    public function test_filling_places_from_the_tray_through_the_page(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(4)->for($floor)->create();

        $desks = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('fillArea', 10.0, 10.0, 30.0, 30.0, 2, 2)
            ->assertHasNoErrors()
            ->instance()
            ->planDesks();

        $this->assertSame([null], array_unique(array_map(
            fn (array $desk): ?bool => $desk['x'] === null ?: null,
            $desks,
        )));
        $this->assertSame(4, $floor->workstations()->placed()->count());
    }

    /**
     * The box arrives from the browser as four numbers. Nothing stops a crafted
     * call asking for one that runs from 400% to -50%, backwards.
     */
    public function test_a_box_from_outside_the_plan_is_clamped_and_squared_up(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(2)->for($floor)->sequence(['name' => 'A-1'], ['name' => 'A-2'])->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('fillArea', 400.0, 90.0, -50.0, 10.0, 2, 1);

        // x came in backwards and off both ends, so it is squared up to 0..100
        // and the two desks take its corners. y came in bottom-first and is
        // squared up to 10..90; a single row sits down the middle of it.
        $this->assertSame([[0.0, 50.0], [100.0, 50.0]], $this->coordinates($floor));
    }

    /**
     * A crafted 10,000 x 10,000 would build a hundred million positions on its
     * way to discovering the tray held two desks.
     */
    public function test_an_absurd_grid_is_capped(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(2)->for($floor)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('fillArea', 0.0, 0.0, 100.0, 100.0, 100000, 100000);

        $this->assertSame(2, $floor->workstations()->placed()->count());
    }

    public function test_filling_cannot_reach_another_floors_desks(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->create();

        $elsewhere = Floor::factory()->create();
        $untouched = Workstation::factory()->for($elsewhere)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('fillArea', 0.0, 0.0, 100.0, 100.0, 10, 10);

        $this->assertNull($untouched->refresh()->position_x);
    }

    /** Asking for more than there is places what there is, rather than nothing. */
    public function test_asking_for_more_desks_than_the_tray_holds_places_what_it_has(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(3)->for($floor)->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('fillArea', 0.0, 0.0, 100.0, 100.0, 5, 5);

        $this->assertSame(3, $floor->workstations()->placed()->count());
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

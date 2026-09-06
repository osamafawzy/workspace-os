<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class FloorSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_floor_has_dimensions_with_sensible_defaults(): void
    {
        $floor = Floor::query()->create(['name' => 'Plain', 'level' => 40]);

        $this->assertSame(60.0, $floor->refresh()->width_m);
        $this->assertSame(40.0, $floor->depth_m);
    }

    public function test_area_and_capacity_come_off_the_dimensions(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);

        $this->assertSame(2400.0, $floor->area());
        $this->assertSame(300, $floor->deskCapacity());
    }

    public function test_the_aspect_ratio_is_what_every_drawing_of_the_floor_uses(): void
    {
        $wide = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);
        $square = Floor::factory()->create(['width_m' => 30, 'depth_m' => 30]);

        $this->assertSame(1.5, $wide->aspectRatio());
        $this->assertSame(1.0, $square->aspectRatio());
    }

    /**
     * A floor with no shape would divide by zero and take out every page that
     * draws it, so it falls back rather than failing.
     */
    public function test_a_floor_with_no_size_falls_back_to_a_usable_ratio(): void
    {
        $floor = Floor::factory()->create(['width_m' => 0, 'depth_m' => 0]);

        $this->assertSame(1.5, $floor->aspectRatio());
    }

    public function test_arranging_places_three_hundred_desks_inside_the_floor(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);

        $names = [];
        for ($i = 1; $i <= 300; $i++) {
            $names[] = ['floor_id' => $floor->id, 'name' => 'C-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)];
        }
        Workstation::query()->insert($names);

        $placed = app(ArrangeWorkstations::class)->handle($floor);

        $this->assertSame(300, $placed);
        $this->assertSame(0, $floor->workstations()->unplaced()->count());

        foreach ($floor->workstations()->placed()->get() as $desk) {
            $this->assertGreaterThanOrEqual(0, $desk->position_x);
            $this->assertLessThanOrEqual(100, $desk->position_x);
            $this->assertGreaterThanOrEqual(0, $desk->position_y);
            $this->assertLessThanOrEqual(100, $desk->position_y);
        }
    }

    /**
     * Three hundred desks laid out on a fixed 3:2 assumption end up shoulder to
     * shoulder on one axis and metres apart on the other as soon as the floor
     * is not 3:2. The layout has to follow the room.
     */
    public function test_the_arrangement_follows_the_shape_of_the_room(): void
    {
        $wide = Floor::factory()->create(['width_m' => 90, 'depth_m' => 20]);
        $tall = Floor::factory()->create(['width_m' => 20, 'depth_m' => 90]);

        foreach ([$wide, $tall] as $floor) {
            $rows = [];
            for ($i = 1; $i <= 60; $i++) {
                $rows[] = ['floor_id' => $floor->id, 'name' => 'D-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)];
            }
            Workstation::query()->insert($rows);
            app(ArrangeWorkstations::class)->handle($floor);
        }

        $columnsOn = fn (Floor $floor): int => $floor->workstations()
            ->placed()->distinct()->count('position_x');

        // The long room gets more columns than the deep one, which is the whole
        // point of taking the aspect ratio into account.
        $this->assertGreaterThan($columnsOn($tall), $columnsOn($wide));
    }

    public function test_arranging_leaves_placed_desks_alone_and_other_floors_untouched(): void
    {
        $floor = Floor::factory()->create();
        $settled = Workstation::factory()->for($floor)->placed(11.0, 89.0)->create();
        Workstation::factory()->count(5)->for($floor)->create();

        $elsewhere = Workstation::factory()->for(Floor::factory())->create();

        app(ArrangeWorkstations::class)->handle($floor);

        $this->assertSame(11.0, $settled->refresh()->position_x);
        $this->assertNull($elsewhere->refresh()->position_x);
    }
}

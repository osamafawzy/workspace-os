<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * A floor resized after its map was drawn: the usual case is setting the real
 * size from the drawing after desks were placed over it. Nothing may move off
 * its spot on the drawing.
 */
class FloorMapScalingTest extends TestCase
{
    use RefreshDatabase;

    public function test_resizing_a_floor_keeps_desks_at_the_same_place_on_the_drawing(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);
        $desk = Workstation::factory()->for($floor)->placed(25, 75)->create();

        $floor->update(['width_m' => 70, 'depth_m' => 50]);

        $object = $desk->refresh()->mapObject;

        $this->assertSame([25.0, 75.0], $desk->planPosition());
        $this->assertSame(17.5, $object->x);
        $this->assertSame(37.5, $object->y);
        // A desk is still a desk-sized desk.
        $this->assertSame(1.4, $object->width);
    }

    public function test_walls_and_rooms_stretch_with_the_floor(): void
    {
        $floor = Floor::factory()->create(['width_m' => 20, 'depth_m' => 10]);
        $along = FloorObject::factory()->for($floor)->wall(20)->create(['x' => 10, 'y' => 0]);
        $across = FloorObject::factory()->for($floor)->wall(10)->create(['x' => 0, 'y' => 5, 'rotation' => 90]);
        $room = FloorObject::factory()->for($floor)->create(['type' => 'room', 'width' => 4, 'depth' => 2]);

        $floor->update(['width_m' => 40, 'depth_m' => 30]);

        $this->assertSame(40.0, $along->refresh()->width);
        // Turned side-on, its length runs along the floor's depth.
        $this->assertSame(30.0, $across->refresh()->width);
        $this->assertSame(8.0, $room->refresh()->width);
        $this->assertSame(6.0, $room->depth);
    }

    public function test_a_resize_bumps_the_revision_so_an_open_editor_cannot_save_over_it(): void
    {
        $floor = Floor::factory()->create(['width_m' => 20, 'depth_m' => 10]);
        FloorObject::factory()->for($floor)->create();

        $floor->update(['width_m' => 30]);

        $this->assertSame(1, $floor->refresh()->map_revision);
    }

    public function test_renaming_a_floor_moves_nothing(): void
    {
        $floor = Floor::factory()->create();
        $object = FloorObject::factory()->for($floor)->create(['x' => 3]);

        $floor->update(['name' => 'Renamed']);

        $this->assertSame(3.0, $object->refresh()->x);
        $this->assertSame(0, $floor->refresh()->map_revision);
    }

    /**
     * Every placed desk becomes a workstation object at the same spot, now in
     * metres, and the old percentage columns go.
     */
    public function test_the_migration_moves_placements_onto_the_map(): void
    {
        $migration = require base_path('Modules/Workspace/database/migrations/2026_09_20_000010_create_floor_objects_table.php');
        $migration->down();

        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);
        $now = now();
        DB::table('workstations')->insert([
            ['floor_id' => $floor->id, 'name' => 'A-01', 'status' => 'active', 'position_x' => 50, 'position_y' => 25, 'created_at' => $now, 'updated_at' => $now],
            ['floor_id' => $floor->id, 'name' => 'A-02', 'status' => 'active', 'position_x' => null, 'position_y' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $migration->up();

        $placed = Workstation::query()->where('name', 'A-01')->firstOrFail();

        $this->assertSame(2, Workstation::query()->count());
        $this->assertSame([50.0, 25.0], $placed->planPosition());
        $this->assertSame(30.0, $placed->mapObject->x);
        $this->assertSame(10.0, $placed->mapObject->y);
        $this->assertFalse(Workstation::query()->where('name', 'A-02')->firstOrFail()->isPlaced());
    }
}

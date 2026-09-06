<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class WorkstationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workstation_belongs_to_a_floor(): void
    {
        $floor = Floor::factory()->create(['name' => 'First Floor']);
        $desk = Workstation::factory()->for($floor)->create();

        $this->assertTrue($desk->floor->is($floor));
    }

    /**
     * The MVP promise: a desk is recordable with nothing but a name and the
     * floor it stands on.
     */
    public function test_a_workstation_needs_only_a_name_and_a_floor(): void
    {
        $floor = Floor::factory()->create();

        $desk = $floor->workstations()->create(['name' => 'A-01']);

        $this->assertDatabaseHas('workstations', [
            'id' => $desk->id,
            'floor_id' => $floor->id,
            'name' => 'A-01',
            'position_x' => null,
            'position_y' => null,
        ]);
    }

    public function test_names_are_unique_within_a_floor(): void
    {
        $floor = Floor::factory()->create();
        $floor->workstations()->create(['name' => 'A-01']);

        $this->expectException(QueryException::class);

        $floor->workstations()->create(['name' => 'A-01']);
    }

    public function test_the_same_name_is_allowed_on_a_different_floor(): void
    {
        $first = Floor::factory()->create();
        $second = Floor::factory()->create();

        $first->workstations()->create(['name' => 'A-01']);
        $second->workstations()->create(['name' => 'A-01']);

        $this->assertSame(2, Workstation::query()->where('name', 'A-01')->count());
    }

    public function test_a_workstation_is_placed_only_when_both_coordinates_are_set(): void
    {
        $unplaced = Workstation::factory()->create();
        $halfDragged = Workstation::factory()->create(['position_x' => 12.5]);
        $placed = Workstation::factory()->placed(12.5, 40.0)->create();

        $this->assertFalse($unplaced->isPlaced());
        $this->assertFalse($halfDragged->isPlaced());
        $this->assertTrue($placed->isPlaced());
    }

    public function test_the_placed_and_unplaced_scopes_partition_the_desks(): void
    {
        $placed = Workstation::factory()->placed()->create();
        $unplaced = Workstation::factory()->create();

        $this->assertSame([$placed->id], Workstation::query()->placed()->pluck('id')->all());
        $this->assertSame([$unplaced->id], Workstation::query()->unplaced()->pluck('id')->all());
    }

    public function test_coordinates_survive_the_round_trip_as_numbers(): void
    {
        $desk = Workstation::factory()->placed(33.33, 66.67)->create()->fresh();

        $this->assertSame(33.33, $desk->position_x);
        $this->assertSame(66.67, $desk->position_y);
    }

    public function test_the_display_label_names_the_floor_the_desk_is_on(): void
    {
        $floor = Floor::factory()->create(['name' => 'Second Floor']);
        $desk = Workstation::factory()->for($floor)->create(['name' => 'B-04']);

        $this->assertSame('Second Floor · B-04', $desk->displayLabel());
    }
}

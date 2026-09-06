<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class FloorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_floor_owns_its_workstations(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(3)->for($floor)->create();

        $this->assertCount(3, $floor->workstations);
    }

    /**
     * The desks belong to the floor physically, not just in the schema — if
     * the floor record goes, the desks on it go with it rather than becoming
     * orphans pointing at a missing building.
     */
    public function test_deleting_a_floor_deletes_its_workstations(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(2)->for($floor)->create();
        $survivor = Workstation::factory()->create();

        $floor->delete();

        $this->assertSame(1, Workstation::query()->count());
        $this->assertDatabaseHas('workstations', ['id' => $survivor->id]);
    }

    public function test_two_floors_cannot_share_a_level(): void
    {
        Floor::factory()->create(['level' => 3, 'name' => 'Third']);

        $this->expectException(QueryException::class);

        Floor::factory()->create(['level' => 3, 'name' => 'Also Third']);
    }

    public function test_two_floors_cannot_share_a_name(): void
    {
        Floor::factory()->create(['name' => 'Mezzanine', 'level' => 10]);

        $this->expectException(QueryException::class);

        Floor::factory()->create(['name' => 'Mezzanine', 'level' => 11]);
    }

    public function test_floors_are_listed_from_the_bottom_of_the_building_up(): void
    {
        Floor::factory()->create(['name' => 'Second', 'level' => 2]);
        Floor::factory()->create(['name' => 'Basement', 'level' => -1]);
        Floor::factory()->create(['name' => 'Ground', 'level' => 0]);

        $this->assertSame(
            ['Basement', 'Ground', 'Second'],
            Floor::query()->inBuildingOrder()->pluck('name')->all(),
        );
    }

    public function test_the_active_scope_excludes_retired_floors(): void
    {
        $live = Floor::factory()->create(['name' => 'Live', 'level' => 20]);
        Floor::factory()->inactive()->create(['name' => 'Retired', 'level' => 21]);

        $this->assertSame([$live->id], Floor::query()->active()->pluck('id')->all());
    }

    public function test_a_floor_reports_whether_it_has_a_plan_drawing(): void
    {
        $bare = Floor::factory()->create();
        $drawn = Floor::factory()->create(['plan_path' => 'plans/first.svg']);

        $this->assertFalse($bare->hasPlan());
        $this->assertTrue($drawn->hasPlan());
    }
}

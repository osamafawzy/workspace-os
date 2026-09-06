<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Workspace\Database\Seeders\WorkspaceDatabaseSeeder;
use Modules\Workspace\Models\Floor;
use Tests\TestCase;

/**
 * The demo building.
 *
 * Worth testing because it is the only place the product is exercised end to
 * end against a real drawing — a floor whose desks stand where an architect
 * put them rather than on a lattice. If the seeder quietly stops attaching the
 * plan, every screenshot still looks plausible and nothing else fails.
 */
class WorkspaceSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(WorkspaceDatabaseSeeder::class);
    }

    public function test_the_ground_floor_is_drawn_against_a_real_plan(): void
    {
        $ground = Floor::query()->where('level', 0)->firstOrFail();

        $this->assertTrue($ground->hasPlan());
        Storage::disk('public')->assertExists($ground->plan_path);

        // Measured from the drawing, not from the floor's 70 x 50 m, which is
        // a 1.4 room either way but arrives at it by a different route.
        $this->assertSame(1.3985, $ground->planAspectRatio());
    }

    public function test_every_ground_floor_desk_stands_somewhere_on_the_drawing(): void
    {
        $ground = Floor::query()->where('level', 0)->firstOrFail();
        $desks = $ground->workstations()->get();

        $this->assertCount(72, $desks);
        $this->assertSame(0, $ground->workstations()->unplaced()->count());

        foreach ($desks as $desk) {
            $this->assertGreaterThanOrEqual(0, $desk->position_x);
            $this->assertLessThanOrEqual(100, $desk->position_x);
            $this->assertGreaterThanOrEqual(0, $desk->position_y);
            $this->assertLessThanOrEqual(100, $desk->position_y);
        }
    }

    /**
     * The zones on the patching sheet are the zones printed on the drawing.
     * A desk whose record says "Zone C" has to be standing in the block the
     * drawing labels Zone C, or the sheet is describing a different building.
     */
    public function test_the_desks_carry_the_zone_the_drawing_marks_them_in(): void
    {
        $ground = Floor::query()->where('level', 0)->firstOrFail();

        $this->assertSame(
            ['Zone A', 'Zone B', 'Zone C', 'Zone D'],
            $ground->workstations()
                ->whereNotNull('zone_number')
                ->distinct()
                ->orderBy('zone_number')
                ->pluck('zone_number')
                ->all(),
        );

        // Zone A is the bank along the top of the drawing, Zone B the one along
        // the bottom. If those ever swap, the plan and the sheet disagree.
        $this->assertLessThan(
            $ground->workstations()->where('zone_number', 'Zone B')->min('position_y'),
            $ground->workstations()->where('zone_number', 'Zone A')->max('position_y'),
        );
    }

    /**
     * Every screen that tells "recorded" from "not yet" — the colour of the
     * marker on the plan, the badge in the list, the filter, the empty modal —
     * needs both states present to be worth looking at.
     */
    public function test_the_building_has_both_traced_and_untraced_desks(): void
    {
        $ground = Floor::query()->where('level', 0)->firstOrFail();

        $this->assertSame(68, $ground->workstations()->withDetails()->count());
        $this->assertSame(4, $ground->workstations()->withDetails(false)->count());
    }

    /** Re-running it must not produce a second building. */
    public function test_seeding_twice_leaves_the_same_building(): void
    {
        $this->seed(WorkspaceDatabaseSeeder::class);

        $this->assertSame(4, Floor::query()->count());
        $this->assertSame(72, Floor::query()->where('level', 0)->firstOrFail()->workstations()->count());
    }
}

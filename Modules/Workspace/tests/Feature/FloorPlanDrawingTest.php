<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Workspace\Models\Floor;
use Tests\TestCase;

/**
 * The plan drawing as a coordinate space.
 *
 * A desk at 40%, 60% means 40% across and 60% down *the drawing*, so anything
 * that frames the drawing has to be the drawing's shape. Frame a 1.4 picture
 * in a 1.5 window and every desk lands beside the one it is meant to mark.
 */
class FloorPlanDrawingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_floor_with_no_drawing_has_no_plan_shape(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);

        $this->assertNull($floor->planAspectRatio());
    }

    public function test_the_drawing_is_measured_from_the_file_itself(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'floor-plans',
            UploadedFile::fake()->image('plan.png', 1088, 778),
            'plan.png',
        );

        // The floor says 3:2. The drawing does not, and the drawing wins,
        // because the drawing is what the coordinates are measured against.
        $floor = Floor::factory()->create([
            'width_m' => 60,
            'depth_m' => 40,
            'plan_path' => 'floor-plans/plan.png',
        ]);

        $this->assertSame(1.3985, $floor->planAspectRatio());
    }

    /**
     * Measuring goes to disk, and this is called once per pin while a
     * three-hundred-desk floor draws itself.
     */
    public function test_the_drawing_is_measured_once_per_floor(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'floor-plans',
            UploadedFile::fake()->image('plan.png', 400, 200),
            'plan.png',
        );

        $floor = Floor::factory()->create(['plan_path' => 'floor-plans/plan.png']);

        $this->assertSame(2.0, $floor->planAspectRatio());

        // Taking the file away afterwards must not change the answer: if it
        // does, the answer was being worked out again.
        Storage::disk('public')->delete('floor-plans/plan.png');

        $this->assertSame(2.0, $floor->planAspectRatio());
    }

    /**
     * An SVG has no size in a header getimagesize can read, and a drawing can
     * go missing from disk. Neither may take out the page that draws it — both
     * fall back to the floor's own metres, which is what the product did before
     * drawings existed at all.
     */
    public function test_a_drawing_that_cannot_be_measured_falls_back_to_the_floor(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('floor-plans/plan.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $vector = Floor::factory()->create([
            'width_m' => 30,
            'depth_m' => 20,
            'plan_path' => 'floor-plans/plan.svg',
        ]);

        $missing = Floor::factory()->create([
            'width_m' => 30,
            'depth_m' => 20,
            'plan_path' => 'floor-plans/gone.png',
        ]);

        $this->assertNull($vector->planAspectRatio());
        $this->assertNull($missing->planAspectRatio());
    }
}

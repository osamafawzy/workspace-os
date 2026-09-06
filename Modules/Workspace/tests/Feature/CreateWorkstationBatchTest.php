<?php

namespace Modules\Workspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Actions\CreateWorkstationBatch;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class CreateWorkstationBatchTest extends TestCase
{
    use RefreshDatabase;

    protected CreateWorkstationBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = app(CreateWorkstationBatch::class);
    }

    public function test_it_creates_a_run_of_numbered_desks(): void
    {
        $floor = Floor::factory()->create();

        $result = $this->batch->handle($floor, 'A-', 300);

        $this->assertSame(300, $result['created']);
        $this->assertSame(300, $floor->workstations()->count());
        $this->assertDatabaseHas('workstations', ['floor_id' => $floor->id, 'name' => 'A-001']);
        $this->assertDatabaseHas('workstations', ['floor_id' => $floor->id, 'name' => 'A-300']);
    }

    /** Padding is what keeps A-9 sorting before A-10 rather than after it. */
    public function test_numbers_are_padded_so_names_sort_the_way_people_read_them(): void
    {
        $floor = Floor::factory()->create();

        $this->batch->handle($floor, 'A-', 12, 1, 3);

        $this->assertSame(
            ['A-001', 'A-002', 'A-003'],
            $floor->workstations()->orderBy('name')->limit(3)->pluck('name')->all(),
        );
    }

    public function test_it_can_start_from_any_number(): void
    {
        $floor = Floor::factory()->create();

        $this->batch->handle($floor, 'B-', 3, 100);

        $this->assertSame(
            ['B-100', 'B-101', 'B-102'],
            $floor->workstations()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_new_desks_start_unplaced(): void
    {
        $floor = Floor::factory()->create();

        $this->batch->handle($floor, 'A-', 5);

        $this->assertSame(5, $floor->workstations()->unplaced()->count());
    }

    /**
     * Names are unique per floor in the database, so a clash part-way through
     * would abort the whole batch. Skipping is what somebody re-running a batch
     * to fill a gap actually wants.
     */
    public function test_it_skips_names_that_already_exist_rather_than_failing(): void
    {
        $floor = Floor::factory()->create();
        $floor->workstations()->create(['name' => 'A-002']);

        $result = $this->batch->handle($floor, 'A-', 4);

        $this->assertSame(3, $result['created']);
        $this->assertSame(['A-002'], $result['skipped']);
        $this->assertSame(4, $floor->workstations()->count());
    }

    public function test_the_same_names_are_free_on_another_floor(): void
    {
        $first = Floor::factory()->create();
        $second = Floor::factory()->create();

        $this->batch->handle($first, 'A-', 3);
        $result = $this->batch->handle($second, 'A-', 3);

        $this->assertSame(3, $result['created']);
        $this->assertSame(6, Workstation::query()->count());
    }

    public function test_a_count_beyond_the_ceiling_is_capped_rather_than_run(): void
    {
        $floor = Floor::factory()->create();

        $result = $this->batch->handle($floor, 'A-', 5000, 1, 4);

        $this->assertSame(CreateWorkstationBatch::MAX, $result['created']);
    }

    public function test_asking_for_nothing_does_nothing(): void
    {
        $floor = Floor::factory()->create();

        $result = $this->batch->handle($floor, 'A-', 0);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $floor->workstations()->count());
    }

    public function test_an_empty_prefix_gives_plain_numbers(): void
    {
        $floor = Floor::factory()->create();

        $this->batch->handle($floor, '', 3, 1, 2);

        $this->assertSame(['01', '02', '03'], $floor->workstations()->orderBy('name')->pluck('name')->all());
    }
}

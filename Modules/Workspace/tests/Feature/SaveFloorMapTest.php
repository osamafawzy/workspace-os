<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Workspace\Actions\SaveFloorMap;
use Modules\Workspace\FloorMap\FloorMapConflict;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * Saving a map. Everything in the payload comes from a browser, so this is
 * mostly about what is refused.
 */
class SaveFloorMapTest extends TestCase
{
    use RefreshDatabase;

    protected Floor $floor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->floor = Floor::factory()->create(['width_m' => 30, 'depth_m' => 20]);
    }

    /** @return array<string, mixed> */
    protected function object(array $overrides = []): array
    {
        return [
            'id' => null, 'type' => 'desk', 'workstation_id' => null, 'label' => null,
            'x' => 5, 'y' => 5, 'z' => 0, 'width' => 1.4, 'depth' => 0.75, 'height' => 0.75,
            'rotation' => 0, 'props' => [], 'locked' => false,
            ...$overrides,
        ];
    }

    /** @param  list<array<string, mixed>>  $objects */
    protected function save(array $objects, ?int $revision = null): array
    {
        return app(SaveFloorMap::class)->handle($this->floor->fresh(), $revision ?? $this->floor->fresh()->map_revision, $objects);
    }

    /** @param  list<array<string, mixed>>  $objects */
    protected function assertRefused(array $objects): void
    {
        try {
            $this->save($objects);
            $this->fail('The save should have been refused.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_objects_are_created_updated_and_removed_in_one_save(): void
    {
        $keep = FloorObject::factory()->for($this->floor)->create(['x' => 1]);
        $drop = FloorObject::factory()->for($this->floor)->create();

        $result = $this->save([
            $this->object(['id' => $keep->id, 'x' => 9.5, 'rotation' => 450]),
            $this->object(['type' => 'wall', 'width' => 12, 'depth' => 0.2]),
            $this->object(['type' => 'room', 'width' => 6, 'depth' => 4, 'label' => 'Ops']),
        ]);

        $this->assertSame(['created' => 2, 'updated' => 1, 'deleted' => 1], $result['summary']);
        $this->assertModelMissing($drop);
        $this->assertSame(9.5, $keep->refresh()->x);
        // Rotation is kept inside one turn.
        $this->assertSame(90.0, $keep->rotation);
        $this->assertCount(3, $result['objects']);
        $this->assertSame(1, $result['revision']);
    }

    public function test_a_stale_revision_is_a_conflict(): void
    {
        $this->floor->update(['map_revision' => 2]);

        $this->expectException(FloorMapConflict::class);

        $this->save([$this->object()], revision: 1);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->assertRefused([$this->object(['type' => 'spaceship'])]);
    }

    public function test_numbers_out_of_bounds_are_refused(): void
    {
        $this->assertRefused([$this->object(['x' => 1000])]);
        $this->assertRefused([$this->object(['width' => 0])]);
        $this->assertRefused([$this->object(['height' => 'tall'])]);
        $this->assertRefused([$this->object(['type' => 'workstation', 'width' => 50])]);
    }

    public function test_an_object_from_another_floor_cannot_be_touched(): void
    {
        $foreign = FloorObject::factory()->for(Floor::factory())->create(['x' => 1]);

        $this->assertRefused([$this->object(['id' => $foreign->id, 'x' => 7])]);

        $this->assertSame(1.0, $foreign->refresh()->x);
    }

    public function test_a_workstation_object_must_be_a_desk_on_this_floor_and_only_once(): void
    {
        $mine = Workstation::factory()->for($this->floor)->create();
        $theirs = Workstation::factory()->for(Floor::factory())->create();

        $this->assertRefused([$this->object(['type' => 'workstation', 'workstation_id' => $theirs->id, 'width' => 1.4, 'depth' => 1.5])]);
        $this->assertRefused([$this->object(['type' => 'workstation', 'workstation_id' => null, 'width' => 1.4, 'depth' => 1.5])]);
        $this->assertRefused([
            $this->object(['type' => 'workstation', 'workstation_id' => $mine->id, 'width' => 1.4, 'depth' => 1.5]),
            $this->object(['type' => 'workstation', 'workstation_id' => $mine->id, 'width' => 1.4, 'depth' => 1.5, 'x' => 9]),
        ]);

        $this->assertFalse($mine->refresh()->isPlaced());
    }

    /** A plain object cannot quietly claim a desk. */
    public function test_a_non_workstation_object_never_links_a_desk(): void
    {
        $desk = Workstation::factory()->for($this->floor)->create();

        $this->save([$this->object(['type' => 'desk', 'workstation_id' => $desk->id])]);

        $this->assertNull(FloorObject::query()->value('workstation_id'));
    }

    public function test_settings_are_filtered_to_what_the_type_takes(): void
    {
        $this->save([
            $this->object(['type' => 'door', 'width' => 0.9, 'depth' => 0.15, 'props' => ['swing' => 'right', 'color' => '#ff0000', 'onclick' => 'x']]),
            $this->object(['type' => 'text', 'props' => ['text' => 'Reception', 'size' => 99, 'color' => 'red']]),
        ]);

        $this->assertSame(['swing' => 'right'], FloorObject::query()->where('type', 'door')->value('props'));
        // A size out of range and a colour that is not #rrggbb are dropped.
        $this->assertSame(['text' => 'Reception'], FloorObject::query()->where('type', 'text')->value('props'));
    }

    public function test_two_desks_can_swap_places_on_the_map(): void
    {
        $a = Workstation::factory()->for($this->floor)->placed(10, 10)->create();
        $b = Workstation::factory()->for($this->floor)->placed(90, 90)->create();
        $objectA = $a->mapObject;
        $objectB = $b->mapObject;

        $this->save([
            $this->object(['id' => $objectA->id, 'type' => 'workstation', 'workstation_id' => $b->id, 'x' => $objectA->x, 'y' => $objectA->y, 'width' => 1.4, 'depth' => 1.5]),
            $this->object(['id' => $objectB->id, 'type' => 'workstation', 'workstation_id' => $a->id, 'x' => $objectB->x, 'y' => $objectB->y, 'width' => 1.4, 'depth' => 1.5]),
        ]);

        $this->assertSame($objectB->id, $a->refresh()->mapObject->id);
        $this->assertSame($objectA->id, $b->refresh()->mapObject->id);
    }

    public function test_a_desk_taken_off_and_put_back_in_one_save_works(): void
    {
        $desk = Workstation::factory()->for($this->floor)->placed(10, 10)->create();

        $this->save([
            $this->object(['type' => 'workstation', 'workstation_id' => $desk->id, 'x' => 20, 'width' => 1.4, 'depth' => 1.5]),
        ]);

        $this->assertSame(20.0, $desk->refresh()->mapObject->x);
        $this->assertSame(1, FloorObject::query()->count());
    }

    public function test_a_save_is_audited_once_naming_the_desks_moved(): void
    {
        $desk = Workstation::factory()->for($this->floor)->placed(10, 10)->create(['name' => 'WS-024']);

        $this->save([
            $this->object(['id' => $desk->mapObject->id, 'type' => 'workstation', 'workstation_id' => $desk->id, 'x' => 12, 'y' => 3, 'width' => 1.4, 'depth' => 1.5]),
            $this->object(['type' => 'wall', 'width' => 5, 'depth' => 0.2]),
        ]);

        $entries = AuditLog::query()->where('action', 'map saved')->get();

        $this->assertCount(1, $entries);
        $this->assertSame('WS-024', $entries->first()->new_values['desks moved']);
        $this->assertSame(1, $entries->first()->new_values['created']);
    }

    public function test_saving_an_unchanged_map_writes_nothing(): void
    {
        $object = FloorObject::factory()->for($this->floor)->create();

        $result = $this->save([$this->object(['id' => $object->id, 'x' => $object->x, 'y' => $object->y])]);

        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0], $result['summary']);
        $this->assertSame(0, AuditLog::query()->where('action', 'map saved')->count());
    }
}

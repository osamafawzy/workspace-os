<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * The map page: what it hands the editor, and what it accepts back.
 *
 * The editing itself happens in the browser; these prove the server half —
 * the config the editor starts from and the save and create-desk calls, the
 * only ways anything the editor did reaches the database.
 */
class FloorPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_map_page_renders_the_editor(): void
    {
        $floor = Floor::factory()->create(['name' => 'First Floor']);
        Workstation::factory()->for($floor)->create(['name' => 'A-01']);

        $this->get("/admin/floors/{$floor->getKey()}/plan")
            ->assertSuccessful()
            ->assertSee('First Floor')
            // The interaction is client side, so "rendered" has to mean the
            // component, its script, its stylesheet and its data arrived.
            ->assertSee('floorMap(', escape: false)
            ->assertSee('components/floor-map.js', escape: false)
            ->assertSee('floor-map.css', escape: false)
            ->assertSee('A-01');
    }

    public function test_the_map_page_is_behind_the_login(): void
    {
        $floor = Floor::factory()->create();

        auth()->logout();

        $this->get("/admin/floors/{$floor->getKey()}/plan")->assertRedirect('/admin/login');
    }

    public function test_the_editor_gets_the_floor_its_objects_its_desks_and_every_type(): void
    {
        $floor = Floor::factory()->create(['width_m' => 30, 'depth_m' => 20]);
        $placed = Workstation::factory()->for($floor)->placed(50, 50)->create(['name' => 'A-01']);
        Workstation::factory()->for($floor)->create(['name' => 'A-02']);
        FloorObject::factory()->for($floor)->wall(8)->create();

        $config = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])->instance()->mapConfig();

        $this->assertSame(30.0, $config['floor']['width']);
        $this->assertCount(2, $config['objects']);
        $this->assertCount(2, $config['desks']);
        $this->assertSame('A-01', $config['desks'][$placed->id]['name']);
        $this->assertSame('#22c55e', $config['desks'][$placed->id]['statusColor']);

        $desk = collect($config['objects'])->firstWhere('workstation_id', $placed->id);
        $this->assertEquals(15.0, $desk['x']);
        $this->assertEquals(10.0, $desk['y']);

        foreach (['workstation', 'desk', 'rack', 'printer', 'room', 'wall', 'door', 'text', 'meeting-room', 'it-room', 'column', 'emergency-exit', 'custom'] as $type) {
            $this->assertContains($type, array_column($config['types'], 'key'));
        }

        $this->assertTrue($config['canArrange']);
    }

    public function test_the_floor_selector_lists_every_floor(): void
    {
        $floor = Floor::factory()->create(['name' => 'Ground']);
        Floor::factory()->create(['name' => 'Roof']);

        $floors = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])->instance()->mapConfig()['floors'];

        $this->assertCount(2, $floors);
        $this->assertTrue(collect($floors)->firstWhere('current', true)['label'] === $floor->fresh()->fullName());
    }

    public function test_saving_the_map_writes_what_changed(): void
    {
        $floor = Floor::factory()->create(['width_m' => 30, 'depth_m' => 20]);
        $desk = Workstation::factory()->for($floor)->create(['name' => 'A-01']);

        $result = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('saveMap', 0, [
                ['id' => null, 'type' => 'workstation', 'workstation_id' => $desk->id, 'x' => 4, 'y' => 5, 'z' => 0, 'width' => 1.4, 'depth' => 1.5, 'height' => 0.75, 'rotation' => 90, 'props' => [], 'locked' => false],
                ['id' => null, 'type' => 'wall', 'x' => 15, 'y' => 0, 'z' => 0, 'width' => 30, 'depth' => 0.2, 'height' => 2.8, 'rotation' => 0, 'props' => [], 'locked' => true],
            ])
            ->assertNotified('Map saved')
            ->effects['returns'][0] ?? null;

        $this->assertTrue($desk->refresh()->isPlaced());
        $this->assertSame(90.0, $desk->mapObject->rotation);
        $this->assertSame(1, $floor->refresh()->map_revision);
        $this->assertSame(2, $floor->mapObjects()->count());
        $this->assertTrue($floor->mapObjects()->where('type', 'wall')->value('locked'));
    }

    public function test_a_viewer_cannot_save_or_create_desks(): void
    {
        $floor = Floor::factory()->create();

        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('saveMap', 0, [])
            ->assertForbidden();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('createDesk', 'WS-9')
            ->assertForbidden();

        $this->assertFalse(
            Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])->instance()->mapConfig()['canArrange'],
        );
    }

    public function test_a_save_from_a_stale_editor_is_refused(): void
    {
        $floor = Floor::factory()->create();
        $floor->update(['map_revision' => 4]);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('saveMap', 3, [
                ['id' => null, 'type' => 'wall', 'x' => 1, 'y' => 1, 'z' => 0, 'width' => 3, 'depth' => 0.2, 'height' => 2.8, 'rotation' => 0],
            ])
            ->assertNotified('Somebody else has changed this map');

        $this->assertSame(0, $floor->mapObjects()->count());
    }

    public function test_a_bad_save_is_refused_with_its_reasons(): void
    {
        $floor = Floor::factory()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('saveMap', 0, [
                ['id' => null, 'type' => 'spaceship', 'x' => 1, 'y' => 1],
            ])
            ->assertNotified('The map was not saved');

        $this->assertSame(0, $floor->mapObjects()->count());
    }

    public function test_a_desk_can_be_created_from_the_map(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->create(['name' => 'WS-001']);

        $page = Livewire::test(FloorPlan::class, ['record' => $floor->getKey()]);

        $page->call('createDesk', 'WS-002');
        $this->assertDatabaseHas('workstations', ['floor_id' => $floor->id, 'name' => 'WS-002', 'status' => 'available']);

        $page->call('createDesk', 'WS-001');
        $this->assertSame(1, $floor->workstations()->where('name', 'WS-001')->count());
    }

    public function test_desks_can_be_added_in_bulk_from_the_map_page(): void
    {
        $floor = Floor::factory()->create(['width_m' => 60, 'depth_m' => 40]);

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('addManyWorkstations', data: [
                'prefix' => 'C-',
                'start_at' => 1,
                'count' => 120,
                'pad' => 3,
                'arrange' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(120, $floor->workstations()->count());
        // "Place them on the plan straight away" was on, so nothing is left
        // in the tray.
        $this->assertSame(0, $floor->workstations()->unplaced()->count());
        $this->assertSame(120, $floor->mapObjects()->where('type', 'workstation')->count());
    }

    public function test_bulk_added_desks_stay_off_the_map_when_asked(): void
    {
        $floor = Floor::factory()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('addManyWorkstations', data: [
                'prefix' => 'D-',
                'start_at' => 1,
                'count' => 5,
                'pad' => 2,
                'arrange' => false,
            ]);

        $this->assertSame(5, $floor->workstations()->unplaced()->count());
    }

    public function test_the_map_uses_the_uploaded_drawing_when_there_is_one(): void
    {
        $bare = Floor::factory()->create();
        $drawn = Floor::factory()->create(['plan_path' => 'floor-plans/first.png']);

        $this->assertNull(Livewire::test(FloorPlan::class, ['record' => $bare->getKey()])->instance()->planImageUrl());

        $this->assertStringContainsString(
            'floor-plans/first.png',
            Livewire::test(FloorPlan::class, ['record' => $drawn->getKey()])->instance()->mapConfig()['floor']['planUrl'],
        );
    }

    /** Status colours on the map follow a details save without losing the draft. */
    public function test_saving_details_tells_the_map_the_new_status(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->placed()->create();

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->callAction('deskDetails', arguments: ['workstation' => $desk->getKey()], data: ['status' => 'faulty'])
            ->assertHasNoActionErrors()
            ->assertDispatched('desk-details-saved', fn (string $name, array $params): bool => $params['workstation'] === $desk->id
                && $params['desk']['status'] === 'faulty'
                && $params['desk']['statusColor'] === '#ef4444');
    }
}

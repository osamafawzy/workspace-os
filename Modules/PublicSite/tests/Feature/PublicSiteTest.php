<?php

namespace Modules\PublicSite\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\PublicSite\Support\BuildingGeometry;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_building_is_public(): void
    {
        Floor::factory()->create(['name' => 'Ground Floor']);

        $this->get('/')
            ->assertSuccessful()
            ->assertSee('The building')
            ->assertSee('Ground Floor');
    }

    public function test_a_floor_page_is_public(): void
    {
        $floor = Floor::factory()->create(['name' => 'First Floor']);
        Workstation::factory()->for($floor)->placed(30.0, 60.0)->create(['name' => 'A-01']);

        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertSee('First Floor')
            ->assertSee('A-01');
    }

    /**
     * The public site is read-only by construction — it has no POST, PUT,
     * PATCH or DELETE routes at all. This is the assertion that keeps it that
     * way when someone adds a route later.
     */
    public function test_the_public_site_exposes_no_writing_routes(): void
    {
        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains((string) $route->getActionName(), 'Modules\\PublicSite'))
            ->reject(fn ($route): bool => $route->methods() === ['GET', 'HEAD'])
            ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $writes);
    }

    public function test_the_scene_carries_each_floor_with_its_placed_desks(): void
    {
        $ground = Floor::factory()->create(['name' => 'Ground Floor', 'level' => 0]);
        Workstation::factory()->for($ground)->placed(20.0, 80.0)->create(['name' => 'G-01']);
        Workstation::factory()->for($ground)->create(['name' => 'G-02']);

        $scene = BuildingGeometry::forScene(BuildingGeometry::floors());

        $this->assertCount(1, $scene['floors']);

        $floor = $scene['floors'][0];
        $this->assertSame('Ground Floor', $floor['name']);
        $this->assertSame(0, $floor['level']);
        $this->assertSame(2, $floor['deskCount']);
        $this->assertSame(1, $floor['placedCount']);

        // Only the desk with coordinates is drawable; the other one has no
        // position to put it at, and inventing one would show a desk in the
        // room that is not standing there.
        $this->assertCount(1, $floor['desks']);
        $this->assertSame('G-01', $floor['desks'][0]['name']);
        $this->assertSame(20.0, $floor['desks'][0]['x']);
        $this->assertSame(80.0, $floor['desks'][0]['y']);
        $this->assertSame([], $floor['desks'][0]['groups']);
    }

    /**
     * Clicking a desk in the 3D scene has to be able to show its record, so
     * the record has to be in the scene payload rather than fetched later —
     * this page has no API behind it and is not going to grow one.
     */
    public function test_the_scene_carries_what_each_desk_has_recorded(): void
    {
        $ground = Floor::factory()->create(['level' => 0]);
        $switch = NetworkSwitch::factory()->create(['building_id' => $ground->building_id, 'number' => 'SW-03']);
        Workstation::factory()->for($ground)->placed()->create([
            'name' => 'G-01',
            'switch_port_id' => SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi1/0/24'])->id,
            // Recorded, but not something the public site shows.
            'ip_address' => '10.20.30.40',
        ]);

        $desk = BuildingGeometry::forScene(BuildingGeometry::floors())['floors'][0]['desks'][0];

        // Location and Machine have nothing public in them, so they are left
        // out; the untraced split and MAC inside Network are kept, because a
        // blank beside filled fields says "not traced yet" rather than "no
        // such field". The IP address is recorded but never sent.
        $this->assertSame(['Network'], array_column($desk['groups'], 'title'));
        $this->assertSame([
            ['label' => 'Switch', 'value' => 'SW-03', 'mono' => false],
            ['label' => 'Port', 'value' => 'Gi1/0/24', 'mono' => true],
            ['label' => 'Port Split Number', 'value' => null, 'mono' => false],
            ['label' => 'MAC Address', 'value' => null, 'mono' => true],
        ], $desk['groups'][0]['rows']);
    }

    /**
     * The list beside the plan shows unplaced desks too, and those rows open
     * the same modal — so the payload cannot be limited to what is drawable.
     */
    public function test_the_floor_page_carries_every_desk_placed_or_not(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed(30.0, 40.0)->create(['name' => 'A-01']);
        Workstation::factory()->for($floor)->create(['name' => 'A-02', 'computer_name' => 'HQ-WS-0002']);

        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertSee('data-desks', escape: false)
            ->assertSee('HQ-WS-0002')
            // Both desks are clickable, the unplaced one from the list.
            ->assertSee('data-desk=', escape: false);
    }

    public function test_a_desk_that_has_not_been_placed_carries_no_coordinates(): void
    {
        $floor = Floor::factory()->create();
        $desk = Workstation::factory()->for($floor)->create(['name' => 'A-01']);

        $described = BuildingGeometry::describe(collect([$desk]), $floor)[0];

        $this->assertNull($described['x']);
        $this->assertNull($described['y']);
        $this->assertSame($floor->name, $described['floor']);
    }

    /**
     * A desk record is typed by an admin and read by everyone. Blade's @json
     * leaves angle brackets alone, so a note holding a closing script tag
     * would end the payload block early and run whatever came after it on a
     * page any visitor can open.
     */
    public function test_a_desk_note_cannot_break_out_of_the_payload_script(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create([
            'name' => 'A-01',
            'notes' => '</script><script>alert(1)</script>',
        ]);

        foreach ([route('building.floor', $floor), '/'] as $url) {
            $body = $this->get($url)->assertSuccessful()->getContent();

            $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
            $this->assertStringContainsString('\u003C/script\u003E', $body);
        }
    }

    public function test_both_public_pages_carry_the_desk_modal(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create();

        $this->get('/')->assertSuccessful()->assertSee('data-desk-modal', escape: false);
        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertSee('data-desk-modal', escape: false);
    }

    public function test_the_scene_is_ordered_from_the_bottom_of_the_building_up(): void
    {
        Floor::factory()->create(['name' => 'Second', 'level' => 2]);
        Floor::factory()->create(['name' => 'Basement', 'level' => -1]);
        Floor::factory()->create(['name' => 'Ground', 'level' => 0]);

        $scene = BuildingGeometry::forScene(BuildingGeometry::floors());

        $this->assertSame(
            ['Basement', 'Ground', 'Second'],
            array_column($scene['floors'], 'name'),
        );
    }

    public function test_inactive_floors_are_not_shown_or_reachable(): void
    {
        $retired = Floor::factory()->inactive()->create(['name' => 'Retired Floor']);
        Floor::factory()->create(['name' => 'Ground Floor']);

        $this->get('/')
            ->assertSuccessful()
            ->assertSee('Ground Floor')
            ->assertDontSee('Retired Floor');

        $this->get(route('building.floor', $retired))->assertNotFound();
    }

    public function test_a_floor_with_nothing_placed_says_so_rather_than_drawing_an_empty_room(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(2)->for($floor)->create();

        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertSee('No workstation on this floor has been positioned yet.');
    }

    public function test_an_empty_building_does_not_render_a_broken_scene(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertSee('No floors have been added yet.')
            ->assertDontSee('data-canvas', escape: false);
    }

    public function test_the_floor_page_shows_the_plan_drawing_when_there_is_one(): void
    {
        $bare = Floor::factory()->create();
        Workstation::factory()->for($bare)->placed()->create();

        $drawn = Floor::factory()->create(['plan_path' => 'floor-plans/first.png']);
        Workstation::factory()->for($drawn)->placed()->create();

        $this->get(route('building.floor', $bare))->assertDontSee('floor-plans/first.png');
        $this->get(route('building.floor', $drawn))->assertSee('floor-plans/first.png', escape: false);
    }

    /**
     * The plan is framed at the drawing's shape, not the floor's metres — the
     * coordinates are percentages of the drawing, so a frame of any other
     * shape puts every marker beside the desk it is for.
     */
    public function test_the_floor_page_frames_the_drawing_at_its_own_shape(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'floor-plans',
            UploadedFile::fake()->image('plan.png', 400, 200),
            'plan.png',
        );

        $floor = Floor::factory()->create([
            'width_m' => 60,
            'depth_m' => 40,
            'plan_path' => 'floor-plans/plan.png',
        ]);
        Workstation::factory()->for($floor)->placed()->create();

        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertSee('aspect-ratio: 2', escape: false);
    }

    /** A desk is a computer, not a dot. */
    public function test_each_desk_on_the_plan_is_drawn_as_a_computer(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create();

        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertSee('ws-pin__icon', escape: false);
    }

    public function test_the_building_page_ships_the_three_js_bundle_and_the_floor_page_does_not(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->placed()->create();

        $this->get('/')->assertSee('building', escape: false);

        // The flat page is also the no-WebGL fallback, so it must not depend
        // on the renderer it exists to replace. It does ship its own small
        // script for the modal, which is Three.js-free by construction.
        $this->get(route('building.floor', $floor))
            ->assertSuccessful()
            ->assertDontSee('PublicSite/resources/js/building.js', escape: false)
            ->assertSee('floor', escape: false);
    }

    /**
     * The scene stacks floors by level, so two buildings' first floors must
     * never be drawn one on top of the other: one building at a time.
     */
    public function test_with_several_buildings_the_scene_shows_one_at_a_time(): void
    {
        $tower = Building::factory()->create(['name' => 'Tower']);
        $annex = Building::factory()->create(['name' => 'Annex']);
        Floor::factory()->for($tower)->create(['name' => 'Tower First', 'level' => 1]);
        $annexFloor = Floor::factory()->for($annex)->create(['name' => 'Annex First', 'level' => 1]);

        $this->get(route('building'))
            ->assertSuccessful()
            ->assertSee('Tower First')
            ->assertDontSee('Annex First')
            // The other building is one click away.
            ->assertSee(route('building', ['building' => $annex->id]), escape: false);

        $this->get(route('building', ['building' => $annex->id]))
            ->assertSuccessful()
            ->assertSee('Annex First')
            ->assertDontSee('Tower First');

        // A floor page lists the other floors of its own building only.
        $this->get(route('building.floor', $annexFloor))
            ->assertSuccessful()
            ->assertDontSee('Tower First');
    }

    public function test_an_unknown_building_is_not_found(): void
    {
        Floor::factory()->create();

        $this->get(route('building', ['building' => 999999]))->assertNotFound();
    }

    public function test_one_building_never_shows_a_building_switcher(): void
    {
        $floor = Floor::factory()->create();

        $this->get(route('building'))
            ->assertSuccessful()
            ->assertSee('The building')
            ->assertDontSee('?building=', escape: false);
    }
}

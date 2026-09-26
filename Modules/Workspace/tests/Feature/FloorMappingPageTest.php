<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class FloorMappingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_floor_is_listed_with_its_map_and_3d_links(): void
    {
        $floor = Floor::factory()->create(['name' => 'Operations Floor']);
        Workstation::factory()->count(3)->for($floor)->create();
        Workstation::factory()->for($floor)->placed(10, 10)->create();

        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get('/admin/floor-mapping')
            ->assertSuccessful()
            ->assertSee('Operations Floor')
            ->assertSee("/admin/floors/{$floor->id}/plan", false)
            ->assertSee(route('building.floor', $floor), false)
            ->assertSeeInOrder(['Workstations', '4', 'On the map', '1']);
    }

    public function test_the_page_needs_floor_view_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view')->create())
            ->get('/admin/floor-mapping')
            ->assertForbidden();
    }
}

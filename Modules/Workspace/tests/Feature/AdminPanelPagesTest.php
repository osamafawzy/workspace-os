<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end renders of the admin screens.
 *
 * The Livewire component tests exercise behaviour; these prove the pages
 * actually come back as HTML — a broken Blade view or a bad navigation icon
 * enum shows up here and nowhere else.
 */
class AdminPanelPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->superAdmin()->create();
    }

    public static function pageProvider(): array
    {
        return [
            'dashboard' => ['/admin'],
            'floors' => ['/admin/floors'],
            'new floor' => ['/admin/floors/create'],
            'workstations' => ['/admin/workstations'],
            'new workstation' => ['/admin/workstations/create'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_the_page_renders(string $url): void
    {
        Workstation::factory()->count(2)->for(Floor::factory())->create();

        $this->actingAs($this->user)
            ->get($url)
            ->assertSuccessful();
    }

    public function test_the_floor_edit_screen_renders_with_its_workstations_tab(): void
    {
        $floor = Floor::factory()->create(['name' => 'First Floor']);
        Workstation::factory()->for($floor)->create(['name' => 'A-01']);

        $this->actingAs($this->user)
            ->get("/admin/floors/{$floor->getKey()}/edit")
            ->assertSuccessful()
            ->assertSee('First Floor');
    }

    public function test_every_admin_page_is_behind_the_login(): void
    {
        foreach (array_column(self::pageProvider(), 0) as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
    }
}

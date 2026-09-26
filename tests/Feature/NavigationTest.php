<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation\Navigation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar comes from config/navigation.php with the overrides saved on
 * Settings → Navigation laid over it.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_the_sidebar_has_every_module_in_order(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeInOrder(['Dashboard', 'Floor Management', 'Asset Management', 'Reports', 'Admin', 'Settings'])
            ->assertSeeInOrder(['Floor Setup', 'Workstations', 'Floor Mapping', 'Search Workstation'])
            ->assertSeeInOrder([
                'Release New Assets', 'Search For Assets', 'Update Assets', 'Return Assets',
                'Search For Returned Assets', 'Employees Data', 'Adding New Headsets Data',
            ])
            ->assertSeeInOrder(['AMT Data Preparation', 'Non Returned Assets / DIF MSA', 'User Permission', 'Roles', 'Audit Log']);
    }

    public function test_a_planned_entry_opens_its_coming_soon_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/coming-soon?item=amt-data-preparation')
            ->assertSuccessful()
            ->assertSee('AMT Data Preparation is not built yet')
            ->assertSee('Planned for phase 9');
    }

    public function test_an_unknown_or_built_entry_has_no_coming_soon_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin/coming-soon?item=nonsense')->assertNotFound();
        $this->get('/admin/coming-soon?item=floor-setup')->assertNotFound();
    }

    public function test_an_entry_can_be_renamed_reordered_and_hidden_without_code(): void
    {
        app(Navigation::class)->saveOverrides([
            'groups' => ['asset-management' => ['label' => 'Assets']],
            'items' => [
                'workstations' => ['label' => 'Desks', 'sort' => 5],
                'headsets' => ['hidden' => true],
            ],
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Assets')
            ->assertDontSee('Asset Management')
            ->assertSeeInOrder(['Desks', 'Floor Setup'])
            ->assertDontSee('Adding New Headsets Data');
    }

    public function test_renaming_a_group_keeps_its_items_inside_it(): void
    {
        app(Navigation::class)->saveOverrides(['groups' => ['floor-management' => ['label' => 'Floors & Desks']]]);

        $this->assertSame('floor-management', app(Navigation::class)->item('floor-setup')['group']);
        $this->assertSame('Floors & Desks', app(Navigation::class)->groups()['floor-management']['label']);
    }

    public function test_hiding_an_entry_does_not_open_the_screen_to_anyone(): void
    {
        app(Navigation::class)->saveOverrides(['items' => ['user-permission' => ['hidden' => true]]]);

        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_screens_a_user_may_not_open_are_not_in_their_sidebar(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Floor Setup')
            ->assertDontSee('User Permission')
            ->assertDontSee('Audit Log');
    }
}

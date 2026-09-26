<?php

namespace Modules\Settings\Tests\Feature;

use App\Models\User;
use App\Support\Navigation\Navigation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Settings\Filament\Admin\Pages\NavigationSettings;
use Tests\TestCase;

class NavigationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_screen_shows_the_current_sidebar(): void
    {
        Livewire::test(NavigationSettings::class)
            ->assertSchemaStateSet([
                'groups.asset_management.label' => 'Asset Management',
                'items.release_new_assets.label' => 'Release New Assets',
                'items.release_new_assets.group' => 'asset-management',
            ]);
    }

    public function test_changes_are_saved_and_reach_the_sidebar(): void
    {
        Livewire::test(NavigationSettings::class)
            ->fillForm([
                'groups.asset_management.label' => 'Assets',
                'items.workstations.label' => 'Desks',
                'items.headsets.hidden' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $navigation = app(Navigation::class);

        $this->assertSame('Assets', $navigation->groups()['asset-management']['label']);
        $this->assertSame('Desks', $navigation->item('workstations')['label']);
        $this->assertTrue($navigation->item('headsets')['hidden']);

        $this->get('/admin')->assertSee('Desks')->assertDontSee('Adding New Headsets Data');
    }

    public function test_only_differences_from_the_defaults_are_stored(): void
    {
        Livewire::test(NavigationSettings::class)
            ->fillForm(['items.workstations.label' => 'Desks'])
            ->call('save');

        $this->assertSame(
            ['groups' => [], 'items' => ['workstations' => ['label' => 'Desks']]],
            app(Navigation::class)->overrides(),
        );
    }

    public function test_an_entry_can_move_to_another_group(): void
    {
        Livewire::test(NavigationSettings::class)
            ->fillForm(['items.employees.group' => 'admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('admin', app(Navigation::class)->item('employees')['group']);
    }

    public function test_reset_puts_everything_back(): void
    {
        app(Navigation::class)->saveOverrides(['items' => ['workstations' => ['label' => 'Desks']]]);

        Livewire::test(NavigationSettings::class)
            ->callAction('reset');

        $this->assertSame([], app(Navigation::class)->overrides());
        $this->assertSame('Workstations', app(Navigation::class)->item('workstations')['label']);
    }

    public function test_the_screen_needs_the_manage_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get('/admin/settings/navigation')
            ->assertForbidden();
    }
}

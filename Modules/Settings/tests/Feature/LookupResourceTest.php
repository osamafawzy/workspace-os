<?php

namespace Modules\Settings\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Settings\Filament\Admin\Resources\Locations\Pages\ManageLocations;
use Modules\Settings\Filament\Admin\Resources\Sites\Pages\ManageSites;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LookupResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public static function screens(): array
    {
        return [
            'sites' => ['/admin/sites'],
            'locations' => ['/admin/locations'],
            'accounts' => ['/admin/accounts'],
            'departments' => ['/admin/departments'],
        ];
    }

    #[DataProvider('screens')]
    public function test_each_list_renders_for_somebody_allowed_to_view_it(string $url): void
    {
        $this->actingAs(User::factory()->withPermissions('lookups.view')->create())
            ->get($url)
            ->assertSuccessful();

        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get($url)
            ->assertForbidden();
    }

    public function test_a_site_can_be_added(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ManageSites::class)
            ->callAction('create', ['name' => 'Alexandria Site', 'code' => 'ALX', 'city' => 'Alexandria', 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('sites', ['name' => 'Alexandria Site', 'code' => 'ALX']);
    }

    public function test_site_names_are_unique(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Site::query()->create(['name' => 'Alexandria Site']);

        Livewire::test(ManageSites::class)
            ->callAction('create', ['name' => 'Alexandria Site'])
            ->assertHasActionErrors(['name' => 'unique']);
    }

    public function test_a_location_name_is_only_unique_within_its_site(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $alexandria = Site::query()->create(['name' => 'Alexandria Site']);
        $cairo = Site::query()->create(['name' => 'Cairo Site']);
        Location::query()->create(['name' => 'Store Room', 'site_id' => $alexandria->id]);

        Livewire::test(ManageLocations::class)
            ->callAction('create', ['name' => 'Store Room', 'site_id' => $cairo->id])
            ->assertHasNoActionErrors();

        Livewire::test(ManageLocations::class)
            ->callAction('create', ['name' => 'Store Room', 'site_id' => $alexandria->id])
            ->assertHasActionErrors(['name' => 'unique']);
    }

    public function test_a_site_with_locations_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $site = Site::query()->create(['name' => 'Alexandria Site']);
        $empty = Site::query()->create(['name' => 'Cairo Site']);
        Location::query()->create(['name' => 'Store Room', 'site_id' => $site->id]);

        Livewire::test(ManageSites::class)
            ->assertTableActionHidden('delete', $site)
            ->callTableAction('delete', $empty);

        $this->assertModelExists($site);
        $this->assertModelMissing($empty);
    }

    public function test_viewing_lists_does_not_allow_changing_them(): void
    {
        $this->actingAs(User::factory()->withPermissions('lookups.view')->create());
        $site = Site::query()->create(['name' => 'Alexandria Site']);

        Livewire::test(ManageSites::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $site)
            ->assertTableActionHidden('delete', $site);
    }
}

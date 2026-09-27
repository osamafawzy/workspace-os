<?php

namespace Modules\Settings\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Assets\Filament\Admin\Resources\AssetTypes\Pages\ManageAssetTypes;
use Modules\Assets\Filament\Admin\Resources\Manufacturers\Pages\ManageManufacturers;
use Modules\Assets\Filament\Admin\Resources\UpdateReasons\Pages\ManageUpdateReasons;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;
use Modules\Assets\Models\UpdateReason;
use Modules\Settings\Filament\Admin\Resources\Accounts\Pages\ManageAccounts;
use Modules\Settings\Filament\Admin\Resources\Departments\Pages\ManageDepartments;
use Modules\Settings\Filament\Admin\Resources\Locations\Pages\ManageLocations;
use Modules\Settings\Filament\Admin\Resources\Sites\Pages\ManageSites;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Lookup;
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

    public function test_a_code_is_refused_on_the_field_rather_than_by_the_database(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $cairo = Site::query()->create(['name' => 'Cairo Site', 'code' => 'CAI']);

        // The case that brought this up: a second site given a code another
        // site already has. It is a message on the field, not a 500.
        Livewire::test(ManageSites::class)
            ->callAction('create', ['name' => 'Cairo Site 4', 'code' => 'CAI', 'city' => 'Cairo'])
            ->assertHasActionErrors(['code' => 'unique']);

        $this->assertSame(1, Site::query()->count());

        // Editing the site that already has the code is not a clash with itself.
        Livewire::test(ManageSites::class)
            ->callTableAction('edit', $cairo, ['name' => 'Cairo Site', 'code' => 'CAI', 'city' => 'Cairo'])
            ->assertHasNoTableActionErrors();

        // And a code is still optional, as many times as you like.
        Livewire::test(ManageSites::class)
            ->callAction('create', ['name' => 'Alexandria Site'])
            ->assertHasNoActionErrors();

        Livewire::test(ManageSites::class)
            ->callAction('create', ['name' => 'Giza Site'])
            ->assertHasNoActionErrors();

        $this->assertSame(3, Site::query()->count());
    }

    public function test_a_location_code_is_only_unique_within_its_site(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $alexandria = Site::query()->create(['name' => 'Alexandria Site']);
        $cairo = Site::query()->create(['name' => 'Cairo Site']);
        Location::query()->create(['name' => 'Store Room', 'site_id' => $alexandria->id, 'code' => 'ST']);

        Livewire::test(ManageLocations::class)
            ->callAction('create', ['name' => 'Store Room', 'site_id' => $cairo->id, 'code' => 'ST'])
            ->assertHasNoActionErrors();

        Livewire::test(ManageLocations::class)
            ->callAction('create', ['name' => 'Second Store', 'site_id' => $alexandria->id, 'code' => 'ST'])
            ->assertHasActionErrors(['code' => 'unique']);
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

    /**
     * Every list whose code the database insists is unique says so on the form.
     *
     * @return array<string, array{0: class-string<Lookup>, 1: class-string}>
     */
    public static function codedLists(): array
    {
        return [
            'sites' => [Site::class, ManageSites::class],
            'accounts' => [Account::class, ManageAccounts::class],
            'departments' => [Department::class, ManageDepartments::class],
            'manufacturers' => [Manufacturer::class, ManageManufacturers::class],
            'asset types' => [AssetType::class, ManageAssetTypes::class],
            'update reasons' => [UpdateReason::class, ManageUpdateReasons::class],
        ];
    }

    #[DataProvider('codedLists')]
    public function test_every_list_with_a_unique_code_checks_it_on_the_form(string $model, string $page): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $model::query()->create(['name' => 'The first one', 'code' => 'DUP', 'is_active' => true]);

        Livewire::test($page)
            ->callAction('create', ['name' => 'The second one', 'code' => 'DUP', 'is_active' => true])
            ->assertHasActionErrors(['code' => 'unique']);

        $this->assertSame(1, $model::query()->count());
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

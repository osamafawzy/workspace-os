<?php

namespace Modules\Assets\Tests\Feature;

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Pages\AssignAssets;
use Modules\Assets\Filament\Admin\Pages\UpdateAssets;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\ListAssets;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\ViewAsset;
use Modules\Assets\Filament\Admin\Resources\AssetTypes\Pages\ManageAssetTypes;
use Modules\Assets\Filament\Admin\Resources\UpdateReasons\Pages\ManageUpdateReasons;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\UpdateReason;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Modules\Workspace\Filament\Admin\Pages\SearchWorkstation;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class AssetScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function admin(): User
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_every_screen_needs_its_permission(): void
    {
        $screens = [
            '/admin/assets' => 'assets.view',
            '/admin/update-assets' => ['assets.view', 'assets.update'],
            '/admin/asset-types' => 'catalogue.view',
            '/admin/manufacturers' => 'catalogue.view',
            '/admin/asset-models' => 'catalogue.view',
            '/admin/update-reasons' => 'catalogue.view',
        ];

        foreach ($screens as $url => $permissions) {
            $this->actingAs(User::factory()->withPermissions('employees.view')->create())->get($url)->assertForbidden();
            $this->actingAs(User::factory()->withPermissions(...(array) $permissions)->create())->get($url)->assertSuccessful();
        }

        // Looking at assets is not enough to use the update screen.
        $this->actingAs(User::factory()->withPermissions('assets.view')->create())->get('/admin/update-assets')->assertForbidden();

        foreach (['search-assets', 'update-assets'] as $item) {
            $this->admin();
            $this->get("/admin/coming-soon?item={$item}")->assertNotFound();
        }
    }

    public function test_an_asset_is_created_but_who_holds_it_is_not_set_from_a_form(): void
    {
        $this->admin();

        $model = AssetModel::factory()->create(['name' => 'Latitude 5440']);
        $employee = Employee::factory()->create();

        // Assigned is not on offer for an asset nobody holds.
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'asset_type_id' => $model->asset_type_id,
                'asset_model_id' => $model->id,
                'serial_number' => 'SN-NEW-1',
                'status' => AssetStatus::Assigned->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['status']);

        Livewire::test(CreateAsset::class)
            ->fillForm([
                'asset_type_id' => $model->asset_type_id,
                'asset_model_id' => $model->id,
                'serial_number' => 'SN-NEW-1',
                'asset_tag' => 'AT-1',
                'status' => AssetStatus::Available->value,
                // Sent anyway, as a crafted request would: ignored.
                'employee_id' => $employee->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = Asset::query()->where('serial_number', 'SN-NEW-1')->firstOrFail();
        $this->assertNull($asset->employee_id);

        // Serials and tags are one per asset.
        Livewire::test(CreateAsset::class)
            ->fillForm(['asset_type_id' => $model->asset_type_id, 'serial_number' => 'SN-NEW-1', 'asset_tag' => 'AT-1', 'status' => AssetStatus::Available->value])
            ->call('create')
            ->assertHasFormErrors(['serial_number' => 'unique', 'asset_tag' => 'unique']);
    }

    public function test_the_list_searches_and_filters(): void
    {
        $this->admin();

        $headsetType = AssetType::factory()->headset()->create(['name' => 'Headset']);
        $headset = Asset::factory()->create([
            'asset_model_id' => AssetModel::factory()->create(['asset_type_id' => $headsetType->id])->id,
            'asset_type_id' => $headsetType->id,
            'serial_number' => 'HS-1',
        ]);
        $expired = Asset::factory()->create(['serial_number' => 'OLD-1', 'warranty_expires_at' => today()->subDay()]);
        $expiring = Asset::factory()->create(['serial_number' => 'SOON-1', 'warranty_expires_at' => today()->addDays(10)]);
        $employee = Employee::factory()->create(['name' => 'Sara Ali']);
        $held = Asset::factory()->create(['serial_number' => 'HELD-1', 'employee_id' => $employee->id]);

        Livewire::test(ListAssets::class)
            ->searchTable('sara')
            ->assertCanSeeTableRecords([$held])
            ->assertCanNotSeeTableRecords([$headset, $expired, $expiring])
            ->searchTable('')
            ->filterTable('warranty', 'expired')
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$expiring, $held])
            ->filterTable('warranty', 'expiring')
            ->assertCanSeeTableRecords([$expiring])
            ->assertCanNotSeeTableRecords([$expired])
            ->resetTableFilters()
            ->filterTable('headsets', true)
            ->assertCanSeeTableRecords([$headset])
            ->assertCanNotSeeTableRecords([$held])
            ->resetTableFilters()
            ->filterTable('status', [AssetStatus::Assigned->value])
            ->assertCanSeeTableRecords([$held])
            ->assertCanNotSeeTableRecords([$headset]);
    }

    public function test_assign_and_return_lead_to_their_screens_and_history_opens(): void
    {
        $this->admin();

        $asset = Asset::factory()->create(['serial_number' => 'SN-HIST']);
        $asset->update(['notes' => 'Screen scratched']);
        $held = Asset::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        Livewire::test(ListAssets::class)
            ->assertTableActionVisible('assign', $asset)
            ->assertTableActionHasUrl('assign', AssignAssets::getUrl(['asset' => $asset->id]), $asset)
            ->assertTableActionHidden('return', $asset)
            ->assertTableActionHidden('assign', $held)
            ->assertTableActionVisible('return', $held)
            ->mountTableAction('history', $asset)
            ->assertMountedActionModalSee(['Registered', 'Updated', 'Screen scratched']);

        $this->get(AssetResource::getUrl('view', ['record' => $asset]))
            ->assertSuccessful()
            ->assertSee('SN-HIST')
            ->assertSee('Screen scratched')
            ->assertSee('History');
    }

    public function test_bulk_move_and_status(): void
    {
        $this->admin();

        $site = Site::factory()->create();
        $location = Location::query()->create(['site_id' => $site->id, 'name' => 'Store']);
        $assets = Asset::factory()->count(2)->create();
        $free = Asset::factory()->create();

        Livewire::test(ListAssets::class)
            ->callTableBulkAction('move', $assets, data: ['site_id' => $site->id, 'location_id' => $location->id])
            ->callTableBulkAction('setStatus', [$free, ...$assets], data: ['status' => AssetStatus::InRepair->value]);

        foreach ($assets as $asset) {
            $this->assertSame($location->id, $asset->fresh()->location_id);
            $this->assertSame(AssetStatus::InRepair, $asset->fresh()->status);
            $this->assertSame('status changed', $asset->history()->value('event'));
        }

        // Assigned is not something a bulk change can make up.
        Livewire::test(ListAssets::class)->callTableBulkAction('setStatus', [$free], data: ['status' => AssetStatus::Assigned->value]);
        $this->assertSame(AssetStatus::InRepair, $free->fresh()->status);
    }

    public function test_the_employee_profile_lists_what_they_hold(): void
    {
        $this->admin();

        $employee = Employee::factory()->create();
        Asset::factory()->create(['serial_number' => 'HELD-BY-HIM', 'employee_id' => $employee->id]);
        Asset::factory()->create(['serial_number' => 'NOT-HIS']);

        Livewire::test(ViewEmployee::class, ['record' => $employee->id])
            ->assertSee('Assigned assets')
            ->assertSee('HELD-BY-HIM')
            ->assertDontSee('NOT-HIS');

        // And they cannot be deleted while they hold it.
        $this->assertFalse(auth()->user()->can('delete', $employee));
    }

    public function test_a_workstations_details_link_to_its_pc_and_monitor(): void
    {
        $this->admin();

        $pc = Asset::factory()->create(['serial_number' => 'PC-SERIAL-1']);
        $monitor = Asset::factory()->create(['serial_number' => 'MON-SERIAL-1']);
        $desk = Workstation::factory()->create(['pc_serial' => ' PC-SERIAL-1', 'monitor_serial' => 'MON-SERIAL-1']);
        $bare = Workstation::factory()->create(['pc_serial' => 'NOT-IN-REGISTER']);

        $details = fn (Workstation $workstation) => TestAction::make('details')->arguments(['workstation' => $workstation->id]);

        Livewire::test(SearchWorkstation::class)
            ->assertActionHasUrl([$details($desk), 'asset'], AssetResource::getUrl('view', ['record' => $pc]))
            ->assertActionHasUrl([$details($desk), 'monitorAsset'], AssetResource::getUrl('view', ['record' => $monitor]));

        // A fresh page: a mounted panel keeps the buttons it was built with.
        Livewire::test(SearchWorkstation::class)
            ->assertActionDisabled([$details($bare), 'asset'])
            ->assertActionHidden([$details($bare), 'monitorAsset']);
    }

    public function test_global_search_finds_an_asset_by_tag(): void
    {
        $this->admin();

        $asset = Asset::factory()->create(['asset_tag' => 'AT-777777']);

        $results = AssetResource::getGlobalSearchResults('777777');

        $this->assertCount(1, $results);
        $this->assertSame(AssetResource::getUrl('view', ['record' => $asset]), $results->first()->url);
    }

    public function test_update_assets_opens_an_asset_from_a_scan_and_saves_it(): void
    {
        $this->admin();

        $site = Site::factory()->create(['name' => 'Alexandria']);
        $asset = Asset::factory()->create(['serial_number' => 'SN-SCAN-1', 'asset_tag' => 'AT-SCAN-1']);
        Asset::factory()->create(['serial_number' => 'SN-SCAN-2', 'asset_tag' => 'AT-OTHER']);

        $page = Livewire::test(UpdateAssets::class)
            ->set('lookup', 'at-scan-1')
            ->call('find')
            ->assertSet('assetId', $asset->id)
            ->assertSee('SN-SCAN-1')
            ->fillForm(['site_id' => $site->id, 'notes' => 'Checked in the store', 'serial_number' => 'SN-SCAN-1'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $asset->refresh();
        $this->assertSame($site->id, $asset->site_id);
        $this->assertSame('Checked in the store', $asset->notes);
        $this->assertSame('moved', $asset->history()->value('event'));

        // A partial label matching several asks which.
        $page->set('lookup', 'SN-SCAN')
            ->call('find')
            ->assertSet('assetId', null)
            ->assertCount('candidates', 2);

        // A serial another asset already has is refused.
        Livewire::test(UpdateAssets::class)
            ->call('open', $asset->id)
            ->fillForm(['serial_number' => 'SN-SCAN-2'])
            ->call('save')
            ->assertHasFormErrors(['serial_number' => 'unique']);
    }

    public function test_update_assets_finds_an_asset_by_the_oid_of_whoever_holds_it(): void
    {
        $this->admin();

        $sara = Employee::factory()->create(['name' => 'Sara Ali', 'oid' => '7654321']);
        $held = Asset::factory()->create(['serial_number' => 'SN-OID-1', 'employee_id' => $sara->id, 'status' => AssetStatus::Assigned]);
        $other = Asset::factory()->create(['serial_number' => 'SN-OID-2', 'employee_id' => Employee::factory()->create(['oid' => '1111111'])->id]);

        // One asset under that OID opens straight away.
        Livewire::test(UpdateAssets::class)
            ->set('lookup', '7654321')
            ->call('find')
            ->assertSet('assetId', $held->id)
            ->assertSee('SN-OID-1');

        // A second one under the same OID asks which.
        $also = Asset::factory()->create(['serial_number' => 'SN-OID-3', 'employee_id' => $sara->id, 'status' => AssetStatus::Assigned]);

        Livewire::test(UpdateAssets::class)
            ->set('lookup', ' 7654321 ')
            ->call('find')
            ->assertSet('assetId', null)
            ->assertCount('candidates', 2)
            ->assertSee('SN-OID-3');

        $this->assertNotNull($also);
        $this->assertNotSame($other->employee_id, $sara->id);
    }

    public function test_a_reason_can_be_chosen_on_an_update_and_is_kept_on_the_history(): void
    {
        $this->admin();

        $faulty = UpdateReason::query()->create(['name' => 'Replacement — faulty hardware', 'code' => 'FAULTY', 'is_active' => true]);
        UpdateReason::query()->create(['name' => 'Retired reason', 'is_active' => false]);
        $asset = Asset::factory()->create(['serial_number' => 'SN-WHY-1', 'notes' => null]);

        Livewire::test(UpdateAssets::class)
            ->call('open', $asset->id)
            // Only the reasons still offered are on the list.
            ->assertFormFieldExists('update_reason_id', checkFieldUsing: function (Select $field) use ($faulty): bool {
                $options = $field->getOptions();

                return $options === [$faulty->id => 'Replacement — faulty hardware'];
            })
            ->fillForm(['update_reason_id' => $faulty->id, 'status' => AssetStatus::InRepair->value])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $history = $asset->history()->latest('id')->first();

        $this->assertSame($faulty->id, $history->asset_update_reason_id);
        // The name as it read at the time, so a later rename leaves history alone.
        $this->assertSame('Replacement — faulty hardware', $history->reason);

        $faulty->update(['name' => 'Faulty hardware']);
        $this->assertSame('Replacement — faulty hardware', $history->fresh()->reason);

        // The reason is for that one save, not for every save after it.
        Livewire::test(UpdateAssets::class)
            ->call('open', $asset->id)
            ->fillForm(['status' => AssetStatus::Available->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($asset->history()->latest('id')->value('asset_update_reason_id'));

        // A reason in use is kept; it is switched off instead of deleted.
        $this->assertTrue($faulty->refresh()->isInUse());
    }

    public function test_the_reasons_are_managed_from_settings(): void
    {
        $this->actingAs(User::factory()->withPermissions('employees.view')->create())->get('/admin/update-reasons')->assertForbidden();
        $this->actingAs(User::factory()->withPermissions('catalogue.view')->create())->get('/admin/update-reasons')->assertSuccessful();

        $this->admin();

        Livewire::test(ManageUpdateReasons::class)
            ->callAction('create', ['name' => 'Lost or stolen', 'code' => 'LOST', 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('asset_update_reasons', ['name' => 'Lost or stolen', 'code' => 'LOST', 'is_active' => true]);
    }

    public function test_the_catalogue_can_be_managed(): void
    {
        $this->admin();

        $this->get('/admin/asset-models')->assertSuccessful();

        Livewire::test(ManageAssetTypes::class)
            ->callAction('create', ['name' => 'Headset', 'is_headset' => true, 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertTrue(AssetType::query()->where('name', 'Headset')->value('is_headset'));

        $this->get(ViewAsset::getUrl(['record' => Asset::factory()->create()]))->assertSuccessful();
    }
}

<?php

namespace Modules\Assets\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Access\Models\Role;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Tests\TestCase;

/**
 * What an asset records about itself, and what it keeps from being deleted.
 */
class AssetRecordTest extends TestCase
{
    use RefreshDatabase;

    /** A Dell Latitude laptop; the first one made is SN-001. */
    protected function asset(array $attributes = []): Asset
    {
        $manufacturer = Manufacturer::query()->firstOrCreate(['name' => 'Dell']);
        $type = AssetType::query()->firstOrCreate(['name' => 'Laptop'], ['has_computer_name' => true]);
        $model = AssetModel::query()->firstOrCreate(
            ['manufacturer_id' => $manufacturer->id, 'name' => 'Latitude 5440'],
            ['asset_type_id' => $type->id],
        );

        return Asset::factory()->create([
            'asset_model_id' => $model->id,
            'serial_number' => Asset::query()->exists() ? 'SN-'.fake()->unique()->numerify('9####') : 'SN-001',
            ...$attributes,
        ]);
    }

    public function test_registering_an_asset_starts_its_history_in_names_not_ids(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Tech One']));

        $asset = $this->asset();
        $entry = $asset->history()->firstOrFail();

        $this->assertSame('registered', $entry->event);
        $this->assertSame('Tech One', $entry->user_name);
        $this->assertSame('Laptop', $entry->changes['asset_type_id']['to']);
        $this->assertSame('Dell Latitude 5440', $entry->changes['asset_model_id']['to']);
        $this->assertSame('Available', $entry->changes['status']['to']);
        $this->assertArrayNotHasKey('employee_id', $entry->changes);
    }

    public function test_moving_assigning_and_unassigning_are_each_recorded(): void
    {
        $asset = $this->asset();
        $site = Site::factory()->create(['name' => 'Alexandria']);
        $second = Location::query()->create(['site_id' => $site->id, 'name' => 'Floor 2']);
        $third = Location::query()->create(['site_id' => $site->id, 'name' => 'Floor 3']);
        $employee = Employee::factory()->create(['name' => 'Ahmed Hassan', 'oid' => '1234567']);

        $asset->update(['site_id' => $site->id, 'location_id' => $second->id]);
        $asset->update(['location_id' => $third->id]);

        $moved = $asset->history()->firstOrFail();
        $this->assertSame('moved', $moved->event);
        $this->assertSame(['from' => 'Floor 2', 'to' => 'Floor 3'], $moved->changes['location_id']);

        // Giving it to somebody marks it assigned, and says when.
        $asset->update(['employee_id' => $employee->id]);
        $asset->refresh();

        $this->assertSame(AssetStatus::Assigned, $asset->status);
        $this->assertNotNull($asset->assigned_at);

        $assigned = $asset->history()->firstOrFail();
        $this->assertSame('assigned', $assigned->event);
        $this->assertSame(['from' => null, 'to' => 'Ahmed Hassan (1234567)'], $assigned->changes['employee_id']);
        $this->assertSame(['from' => 'Available', 'to' => 'Assigned'], $assigned->changes['status']);

        $asset->update(['employee_id' => null]);
        $asset->refresh();

        $this->assertSame(AssetStatus::Available, $asset->status);
        $this->assertNull($asset->assigned_at);
        $this->assertSame('unassigned', $asset->history()->value('event'));

        $this->assertSame(['unassigned', 'assigned', 'moved', 'moved', 'registered'], $asset->history()->pluck('event')->all());
    }

    public function test_a_save_that_changes_nothing_tracked_writes_no_history(): void
    {
        $asset = $this->asset();

        $asset->touch();
        $asset->update(['notes' => null]);

        $this->assertSame(1, $asset->history()->count());
    }

    public function test_history_cannot_be_rewritten(): void
    {
        $entry = $this->asset()->history()->firstOrFail();

        $this->expectException(LogicException::class);

        $entry->update(['event' => 'nothing happened']);
    }

    public function test_serials_and_tags_are_stored_without_stray_spaces(): void
    {
        $asset = $this->asset(['serial_number' => '  SN-XYZ ', 'asset_tag' => ' ']);

        $this->assertSame('SN-XYZ', $asset->fresh()->serial_number);
        $this->assertNull($asset->fresh()->asset_tag);
    }

    public function test_what_an_asset_points_at_cannot_be_deleted(): void
    {
        $employee = Employee::factory()->create();
        $asset = $this->asset(['employee_id' => $employee->id]);
        $site = Site::factory()->create();
        $asset->update(['site_id' => $site->id]);

        $this->assertTrue($asset->assetModel->isInUse());
        $this->assertTrue($asset->assetType->isInUse());
        $this->assertTrue($asset->assetModel->manufacturer->isInUse());
        $this->assertTrue($site->isInUse());
        $this->assertTrue($employee->isInUse());

        $admin = User::factory()->withPermissions('employees.delete', 'catalogue.delete')->create();

        $this->assertFalse($admin->can('delete', $employee));
        $this->assertFalse($admin->can('delete', $asset->assetType));

        // Handing it back does not free them: they signed for it once, and
        // that record points at them.
        $asset->update(['employee_id' => null]);
        $this->assertFalse($admin->can('delete', $employee->fresh()));

        $this->assertTrue($admin->can('delete', Employee::factory()->create()));
    }

    public function test_search_finds_an_asset_by_anything_about_it(): void
    {
        $employee = Employee::factory()->create(['name' => 'Sara Ali', 'oid' => '7654321']);
        $site = Site::factory()->create(['name' => 'Alexandria']);
        $location = Location::query()->create(['site_id' => $site->id, 'name' => 'IT Store']);
        $asset = $this->asset(['asset_tag' => 'AT-000123', 'computer_name' => 'ALX-LT-0123', 'employee_id' => $employee->id, 'site_id' => $site->id, 'location_id' => $location->id]);
        $this->asset(); // another laptop, elsewhere, with nobody

        foreach (['sn-001', 'AT-000123', 'alx-lt', 'sara', '7654321', 'it store', 'latitude sara', 'dell alexandria'] as $term) {
            $this->assertSame([$asset->id], Asset::query()->search($term)->pluck('id')->all(), "Searching for {$term}");
        }

        $this->assertSame([], Asset::query()->search('%')->pluck('id')->all());
    }

    public function test_technicians_update_assets_and_viewers_only_look(): void
    {
        $role = fn (string $name): Role => Role::query()->where('name', $name)->firstOrFail();

        $this->assertTrue($role('IT Technician')->grants('assets.update'));
        $this->assertFalse($role('IT Technician')->grants('assets.delete'));
        $this->assertTrue($role('IT Engineer')->grants('assets.import'));
        $this->assertTrue($role('IT Engineer')->grants('catalogue.create'));
        $this->assertTrue($role('Viewer')->grants('assets.view'));
        $this->assertFalse($role('Viewer')->grants('assets.update'));
    }
}

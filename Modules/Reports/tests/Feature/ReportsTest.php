<?php

namespace Modules\Reports\Tests\Feature;

use App\Models\User;
use App\Support\Reports\ReportExport;
use App\Support\Reports\Reports;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Access\Models\Role;
use Modules\Assets\Actions\AssignAssets;
use Modules\Assets\Actions\ReturnAssets;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetType;
use Modules\Employees\Models\Employee;
use Modules\Reports\Filament\Admin\Pages\PrintReport;
use Modules\Reports\Filament\Admin\Pages\ViewReport;
use Modules\Settings\Models\Account;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public const ALL = [
        'asset-inventory', 'asset-counts', 'assigned-assets', 'available-assets', 'returned-assets', 'non-returned-assets',
        'employee-assets', 'headsets', 'movement-history', 'workstations', 'floors', 'switch-ports', 'racks', 'audit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function admin(): User
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Report Admin']);
        $this->actingAs($user);

        return $user;
    }

    /** A little of everything, so every report has rows to show. */
    protected function world(User $by): array
    {
        $floor = Floor::factory()->create(['name' => 'First Floor']);
        $rack = Rack::factory()->create(['building_id' => $floor->building_id, 'number' => 'R-01']);
        $switch = NetworkSwitch::factory()->create(['building_id' => $floor->building_id, 'rack_id' => $rack->id, 'number' => 'SW-01']);
        $usedPort = SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi1/0/1']);
        SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi1/0/2']);
        Workstation::factory()->for($floor)->placed()->create(['name' => 'A-01', 'switch_port_id' => $usedPort->id, 'status' => WorkstationStatus::Faulty]);
        Workstation::factory()->for($floor)->create(['name' => 'A-02']);

        $sara = Employee::factory()->create(['name' => 'Sara Ali', 'oid' => '7654321']);
        $leaver = Employee::factory()->create(['name' => 'Omar Gone', 'oid' => '1111111']);
        $headsetType = AssetType::factory()->headset()->create(['name' => 'Headset']);

        $laptop = Asset::factory()->create(['serial_number' => 'LT-SARA']);
        $spare = Asset::factory()->create(['serial_number' => 'LT-SPARE']);
        $kept = Asset::factory()->create(['serial_number' => 'LT-KEPT']);
        $back = Asset::factory()->create(['serial_number' => 'LT-BACK']);
        $headset = Asset::factory()->create(['serial_number' => 'HS-1', 'asset_type_id' => $headsetType->id, 'asset_model_id' => null]);

        app(AssignAssets::class)->handle($sara, [$laptop->id, $back->id], $by);
        app(AssignAssets::class)->handle($leaver, [$kept->id], $by);
        app(ReturnAssets::class)->handle([$back->id => 'damaged'], [], $by);
        $leaver->update(['status' => 'left', 'left_at' => now()->subDays(10)]);

        return compact('floor', 'sara', 'leaver', 'laptop', 'spare', 'kept', 'back', 'headset');
    }

    public function test_every_report_is_registered(): void
    {
        // In whatever order the modules booted.
        $this->assertEqualsCanonicalizing(self::ALL, array_keys(app(Reports::class)->all()));
    }

    public function test_every_report_opens_prints_and_exports(): void
    {
        $admin = $this->admin();
        $this->world($admin);

        foreach (self::ALL as $key) {
            $this->get(ViewReport::getUrl(['report' => $key]))->assertSuccessful();
            $this->get(PrintReport::getUrl(['report' => $key]))->assertSuccessful()->assertSee('Print or save as PDF');

            $report = app(Reports::class)->get($key);
            $rows = array_values(iterator_to_array(Spreadsheet::read(ReportExport::download($report, $report->query(), 'csv')->getFile()->getPathname(), 'x.csv')));
            $this->assertSame(array_map(fn ($column) => $column->label, $report->columns()), $rows[0], "Export headings of {$key}");
            $this->assertGreaterThan(1, count($rows), "{$key} should have rows");
        }
    }

    public function test_the_list_shows_only_the_reports_a_user_may_open(): void
    {
        $this->actingAs(User::factory()->withPermissions('assets.view')->create())->get('/admin/reports')->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('reports.view', 'assets.view')->create())
            ->get('/admin/reports')
            ->assertSuccessful()
            ->assertSee('Asset Inventory')
            ->assertSee('Headsets')
            ->assertDontSee('Audit Trail')
            ->assertDontSee('Switch Ports')
            // Employee reports also need employees.view.
            ->assertDontSee('Employee Assets');

        $this->get(ViewReport::getUrl(['report' => 'audit']))->assertForbidden();
        $this->get(ViewReport::getUrl(['report' => 'nope']))->assertNotFound();
    }

    public function test_search_and_filters_narrow_the_screen_the_print_and_the_export(): void
    {
        $admin = $this->admin();
        $world = $this->world($admin);

        Livewire::test(ViewReport::class, ['report' => 'asset-inventory'])
            ->searchTable('sara')
            ->assertCanSeeTableRecords([$world['laptop']])
            ->assertCanNotSeeTableRecords([$world['spare'], $world['kept']])
            ->searchTable('')
            ->filterTable('status', [AssetStatus::Returned->value])
            ->assertCanSeeTableRecords([$world['back']])
            ->assertCanNotSeeTableRecords([$world['laptop']])
            ->callAction('export', data: ['format' => 'csv'])
            ->assertFileDownloaded();

        $this->get(PrintReport::getUrl(['report' => 'asset-inventory', 'search' => 'LT-S']))
            ->assertSuccessful()
            ->assertSee('Search: “LT-S”', escape: false)
            ->assertSee('LT-SARA')
            ->assertSee('LT-SPARE')
            ->assertDontSee('LT-KEPT')
            ->assertSee('2 rows');

        $this->get(PrintReport::getUrl(['report' => 'asset-inventory', 'filters' => ['status' => ['values' => ['returned']]]]))
            ->assertSee('LT-BACK')
            ->assertDontSee('LT-SARA');

        $this->assertDatabaseHas('audit_logs', ['action' => 'printed report', 'record_label' => 'Asset Inventory']);
    }

    public function test_the_reports_say_what_they_say(): void
    {
        $admin = $this->admin();
        $world = $this->world($admin);

        // Assigned: with somebody now, with the form they signed.
        Livewire::test(ViewReport::class, ['report' => 'assigned-assets'])
            ->assertCanSeeTableRecords([$world['laptop'], $world['kept']])
            ->assertCanNotSeeTableRecords([$world['spare'], $world['back']])
            ->assertSee('HO-');

        // Available: nobody has it and it can go out.
        Livewire::test(ViewReport::class, ['report' => 'available-assets'])
            ->assertCanSeeTableRecords([$world['spare'], $world['back'], $world['headset']])
            ->assertCanNotSeeTableRecords([$world['laptop']]);

        // Non-returned: held by somebody who has left.
        Livewire::test(ViewReport::class, ['report' => 'non-returned-assets'])
            ->assertCanSeeTableRecords([$world['kept']])
            ->assertCanNotSeeTableRecords([$world['laptop']])
            ->assertSee('Omar Gone')
            ->assertSee('10');

        Livewire::test(ViewReport::class, ['report' => 'headsets'])
            ->assertCanSeeTableRecords([$world['headset']])
            ->assertCanNotSeeTableRecords([$world['laptop']]);

        // Employee assets: people holding something, by default.
        Livewire::test(ViewReport::class, ['report' => 'employee-assets'])
            ->assertCanSeeTableRecords([$world['sara'], $world['leaver']])
            ->assertSee('LT-SARA');

        Livewire::test(ViewReport::class, ['report' => 'movement-history'])
            ->filterTable('event', ['returned'])
            ->assertSee('LT-BACK')
            ->assertDontSee('LT-SPARE');

        $this->get(PrintReport::getUrl(['report' => 'floors']))->assertSeeInOrder(['First Floor', '2', '1']);
        $this->get(PrintReport::getUrl(['report' => 'racks']))->assertSeeInOrder(['R-01', 'SW-01', '2', '1', '1']);
        $this->get(PrintReport::getUrl(['report' => 'switch-ports', 'filters' => ['in_use' => ['value' => '0']]]))
            ->assertSee('Gi1/0/2')
            ->assertDontSee('Gi1/0/1');
    }

    public function test_asset_counts_are_grouped_by_account_and_type_and_can_be_narrowed_to_one_of_each(): void
    {
        $admin = $this->admin();

        $retail = Account::query()->create(['name' => 'Retail Account']);
        $internal = Account::query()->create(['name' => 'Internal IT']);
        $laptop = AssetType::factory()->create(['name' => 'Laptop']);
        $monitor = AssetType::factory()->create(['name' => 'Monitor']);

        $held = Asset::factory()->count(2)->create(['account_id' => $retail->id, 'asset_type_id' => $laptop->id, 'asset_model_id' => null]);
        Asset::factory()->create(['account_id' => $retail->id, 'asset_type_id' => $laptop->id, 'asset_model_id' => null, 'status' => AssetStatus::InRepair]);
        Asset::factory()->count(4)->create(['account_id' => $retail->id, 'asset_type_id' => $monitor->id, 'asset_model_id' => null]);
        $alone = Asset::factory()->create(['account_id' => $internal->id, 'asset_type_id' => $laptop->id, 'asset_model_id' => null]);

        app(AssignAssets::class)->handle(Employee::factory()->create(), $held->pluck('id')->all(), $admin);

        // Retail's laptops: three of them, two with employees, one in repair.
        $this->get(PrintReport::getUrl(['report' => 'asset-counts', 'filters' => [
            'account_id' => ['value' => (string) $retail->id],
            'asset_type_id' => ['values' => [(string) $laptop->id]],
        ]]))
            ->assertSuccessful()
            ->assertSee('Asset Counts')
            ->assertSeeInOrder(['Retail Account', 'Laptop', '3', '2'])
            ->assertDontSee('Internal IT')
            ->assertDontSee('Monitor')
            // One group, and the totals row under it.
            ->assertSee('1 row')
            ->assertSee('Total');

        // Everything: a row per account and type, and the grand total of all.
        $rows = app(Reports::class)->get('asset-counts')->query()->get();

        $this->assertCount(3, $rows);
        $this->assertSame(8, (int) $rows->sum('total_count'));
        $this->assertSame(2, (int) $rows->sum('held_count'));
        $this->assertSame(1, (int) $rows->sum('in_repair_count'));

        // Nothing orders a grouped report by the assets table's own key: MySQL
        // refuses it (only_full_group_by), and SQLite quietly allows it, so the
        // shape of the query is asserted rather than the result.
        $sorted = Livewire::test(ViewReport::class, ['report' => 'asset-counts'])->instance()->getFilteredSortedTableQuery();
        // Unquoted, so the assertion reads the same on MySQL and on SQLite.
        $sql = fn (string $statement): string => str_replace(['`', '"'], '', $statement);

        $this->assertStringNotContainsString('order by assets.id', $sql($sorted->toSql()));
        $this->assertStringContainsString('order by total_count desc', $sql($sorted->toSql()));

        // And the export, handed a query with no order of its own, pages by
        // what the report groups rather than by that key.
        $counts = app(Reports::class)->get('asset-counts');
        $unordered = $counts->query();
        $file = ReportExport::download($counts, $unordered, 'csv');

        $this->assertStringNotContainsString('order by assets.id', $sql($unordered->toSql()));
        $this->assertStringContainsString('order by assets.account_id', $sql($unordered->toSql()));
        $this->assertFileExists($file->getFile()->getPathname());

        // On screen: three groups, and one once an account is picked.
        Livewire::test(ViewReport::class, ['report' => 'asset-counts'])
            ->assertCountTableRecords(3)
            ->filterTable('account_id', $internal->id)
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$alone]);
    }

    public function test_exporting_and_printing_need_their_own_permissions(): void
    {
        $this->actingAs(User::factory()->withPermissions('reports.view', 'assets.view')->create());

        Livewire::test(ViewReport::class, ['report' => 'asset-inventory'])
            ->assertActionHidden('export')
            ->assertActionHidden('print');

        $this->get(PrintReport::getUrl(['report' => 'asset-inventory']))->assertForbidden();
    }

    public function test_default_roles_see_reports_and_engineers_export_them(): void
    {
        $role = fn (string $name): Role => Role::query()->where('name', $name)->firstOrFail();

        $this->assertTrue($role('Viewer')->grants('reports.view'));
        $this->assertFalse($role('Viewer')->grants('reports.export'));
        $this->assertTrue($role('IT Engineer')->grants('reports.export'));
        $this->assertTrue($role('IT Technician')->grants('reports.print'));
    }
}

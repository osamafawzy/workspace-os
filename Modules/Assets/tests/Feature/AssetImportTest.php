<?php

namespace Modules\Assets\Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\ImportRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Exports\AssetExport;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\ListAssets;
use Modules\Assets\Imports\AssetImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetHistory;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;
use Modules\Employees\Models\Employee;
use Tests\TestCase;

class AssetImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /** @param  list<list<string>>  $rows */
    protected function check(array $rows, array $options = []): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'ast').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return app(ImportRunner::class)->check(app(AssetImporter::class), $path, 'assets.csv', $options, auth()->user());
    }

    /** @return array<int, string> */
    protected function statuses(ImportBatch $batch): array
    {
        return $batch->rows()->orderBy('row_number')->pluck('status', 'row_number')->all();
    }

    protected function messages(ImportBatch $batch, int $row): string
    {
        return implode(' ', collect($batch->rows()->where('row_number', $row)->firstOrFail()->messages)->pluck('text')->all());
    }

    public function test_a_stock_sheet_is_checked_then_imported_creating_the_catalogue_it_names(): void
    {
        $employee = Employee::factory()->create(['oid' => '1234567']);
        Asset::factory()->create(['serial_number' => 'EXISTING-1', 'asset_tag' => 'AT-TAKEN']);

        $monitor = AssetType::factory()->create(['name' => 'Monitor']);
        AssetModel::factory()->for(Manufacturer::factory()->create(['name' => 'Dell']))->create(['name' => 'P2422H', 'asset_type_id' => $monitor->id]);

        $batch = $this->check([
            ['Type', 'Make', 'Model', 'S/N', 'Tag', 'Hostname', 'Status', 'Condition', 'Site', 'Location', 'OID', 'Purchased', 'Warranty End'],
            ['Laptop', 'Dell', 'Latitude 5440', '5CG001', 'AT-001', 'ALX-LT-001', 'In use', 'Good', 'Alexandria', 'Floor 2', '1234567', '15/01/2024', '2027-01-15'],
            ['Monitor', 'Dell', 'P2422H', 'MON-001', '', '', 'In stock', 'New', '', '', '', '', ''],
            ['Laptop', 'Dell', 'Latitude 5440', '5cg001', '', '', '', '', '', '', '', '', ''],
            ['Laptop', 'Dell', 'P2422H', 'BAD-1', 'AT-TAKEN', '', 'Assigned', 'Shiny', '', '', '9999999', '2024-05-01', '2024-01-01'],
            ['Laptop', 'Dell', 'Latitude 5440', 'EXISTING-1', '', '', '', '', '', '', '', '', ''],
        ]);

        $this->assertSame([
            2 => ImportRow::NEW,
            3 => ImportRow::NEW,
            4 => ImportRow::DUPLICATE,
            5 => ImportRow::INVALID,
            6 => ImportRow::UNCHANGED,
        ], $this->statuses($batch));

        $this->assertStringContainsString('Asset type "Laptop" will be created', $this->messages($batch, 2));
        $this->assertStringContainsString('Model "Dell Latitude 5440" will be created', $this->messages($batch, 2));

        $bad = $this->messages($batch, 5);
        $this->assertStringContainsString('Dell P2422H is a Monitor, not a', $bad);
        $this->assertStringContainsString('Asset tag AT-TAKEN is already on EXISTING-1', $bad);
        $this->assertStringContainsString('Condition "Shiny"', $bad);
        $this->assertStringContainsString('no employee with OID 9999999', $bad);
        $this->assertStringContainsString('warranty ends before the purchase date', $bad);

        app(ImportRunner::class)->import($batch, app(AssetImporter::class));

        $laptop = Asset::query()->where('serial_number', '5CG001')->firstOrFail();
        $this->assertSame('Laptop', $laptop->assetType->name);
        $this->assertSame('Dell Latitude 5440', $laptop->assetModel->fullName());
        $this->assertSame($employee->id, $laptop->employee_id);
        $this->assertSame(AssetStatus::Assigned, $laptop->status);
        $this->assertSame(AssetCondition::Good, $laptop->condition);
        $this->assertSame('Floor 2', $laptop->location->name);
        $this->assertSame('2024-01-15', $laptop->purchase_date->toDateString());
        $this->assertSame('registered', $laptop->history()->value('event'));

        $this->assertSame(AssetStatus::Available, Asset::query()->where('serial_number', 'MON-001')->value('status'));
        $this->assertSame(1, Manufacturer::query()->where('name', 'Dell')->count());
    }

    public function test_a_model_name_needs_its_manufacturer_to_be_created(): void
    {
        $batch = $this->check([
            ['Asset Type', 'Model', 'Serial Number'],
            ['Laptop', 'Mystery 9000', 'SN-1'],
        ]);

        $this->assertSame([2 => ImportRow::INVALID], $this->statuses($batch));
        $this->assertStringContainsString('Give its manufacturer', $this->messages($batch, 2));
    }

    public function test_without_the_option_nothing_is_invented(): void
    {
        $batch = $this->check([
            ['Asset Type', 'Manufacturer', 'Serial Number', 'Site'],
            ['Laptop', 'Dell', 'SN-1', 'Nowhere'],
        ], ['create_missing' => false]);

        $this->assertSame([2 => ImportRow::INVALID], $this->statuses($batch));
        $this->assertStringContainsString('Asset type "Laptop" does not exist', $this->messages($batch, 2));
        $this->assertStringContainsString('Site "Nowhere" does not exist', $this->messages($batch, 2));
    }

    public function test_an_export_imports_back_onto_the_same_assets(): void
    {
        $employee = Employee::factory()->create();
        Asset::factory()->count(2)->create();
        Asset::factory()->create(['employee_id' => $employee->id, 'warranty_expires_at' => '2027-01-01', 'notes' => 'Keyboard sticky']);

        $snapshot = fn (): array => Asset::query()->orderBy('id')->get()
            ->map->only(['serial_number', 'asset_tag', 'asset_type_id', 'asset_model_id', 'status', 'condition', 'employee_id', 'notes'])
            ->map(fn (array $row): array => [...$row, 'status' => $row['status']->value, 'condition' => $row['condition']?->value])
            ->all();
        $before = $snapshot();
        $history = AssetHistory::query()->count();

        $file = AssetExport::download(Asset::query(), 'xlsx')->getFile()->getPathname();
        $batch = app(ImportRunner::class)->check(app(AssetImporter::class), $file, 'export.xlsx', ['existing' => ImportRunner::EXISTING_UPDATE], auth()->user());

        $this->assertSame(3, $batch->count(ImportRow::UPDATE));

        app(ImportRunner::class)->import($batch, app(AssetImporter::class));

        $this->assertSame($before, $snapshot());
        // Nothing changed, so nothing new in any asset's history.
        $this->assertSame($history, AssetHistory::query()->count());

        Livewire::test(ListAssets::class)
            ->callAction('export', data: ['format' => 'csv'])
            ->assertFileDownloaded();
    }
}

<?php

namespace Modules\Assets\Tests\Feature;

use App\Filament\Pages\ImportData;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\ImportRunner;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Pages\AddHeadsets;
use Modules\Assets\Imports\HeadsetImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Site;
use Tests\TestCase;

class HeadsetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->withPermissions('assets.view', 'assets.create', 'assets.import')->create());
    }

    public function test_the_page_needs_permission_to_add_assets(): void
    {
        $this->actingAs(User::factory()->withPermissions('assets.view')->create())->get('/admin/headsets')->assertForbidden();

        auth()->logout();
        $this->actingAs(User::factory()->withPermissions('assets.view', 'assets.create')->create())
            ->get('/admin/headsets')
            ->assertSuccessful()
            ->assertSee('Adding New Headsets Data');
    }

    public function test_headsets_are_scanned_in_one_after_another(): void
    {
        $type = AssetType::factory()->headset()->create(['name' => 'Headset']);
        $model = AssetModel::factory()->create(['asset_type_id' => $type->id, 'name' => 'Evolve2 40']);

        Livewire::test(AddHeadsets::class)
            ->assertFormSet(['asset_type_id' => $type->id])
            ->fillForm(['asset_model_id' => $model->id, 'serial_number' => 'HS-001', 'asset_tag' => 'HT-1'])
            ->call('save', true)
            ->assertHasNoFormErrors()
            // The labels are cleared, the rest kept for the next one.
            ->assertFormSet(['serial_number' => null, 'asset_tag' => null, 'asset_model_id' => $model->id])
            ->fillForm(['serial_number' => 'HS-001'])
            ->call('save', true)
            ->assertHasFormErrors(['serial_number' => 'unique'])
            ->fillForm(['serial_number' => 'HS-002'])
            ->call('save', true)
            ->assertHasNoFormErrors()
            ->assertSee('HS-002');

        $this->assertSame(2, Asset::query()->headsets()->count());
    }

    public function test_a_headset_type_is_there_to_pick_even_before_anybody_made_one(): void
    {
        Livewire::test(AddHeadsets::class)->assertSuccessful();

        $this->assertTrue(AssetType::query()->where('name', 'Headset')->value('is_headset'));
    }

    public function test_a_spreadsheet_of_headsets_reports_successes_duplicates_and_failures(): void
    {
        $type = AssetType::factory()->headset()->create(['name' => 'Headset']);
        Asset::factory()->create(['serial_number' => 'HS-EXISTS']);

        $path = tempnam(sys_get_temp_dir(), 'hs').'.csv';
        $handle = fopen($path, 'w');

        foreach ([
            ['Serial Number', 'Manufacturer', 'Model', 'Asset Tag'],
            ['HS-1', 'Jabra', 'Evolve2 40', 'HT-1'],
            ['HS-2', 'Jabra', 'Evolve2 40', ''],
            ['HS-1', '', '', ''],
            ['HS-EXISTS', '', '', ''],
            ['', 'Jabra', '', ''],
        ] as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $runner = app(ImportRunner::class);
        $importer = app(HeadsetImporter::class);
        $batch = $runner->check($importer, $path, 'headsets.csv', ['headset_type_id' => $type->id], auth()->user());

        $this->assertSame([2 => ImportRow::NEW, 3 => ImportRow::NEW, 4 => ImportRow::DUPLICATE, 5 => ImportRow::UNCHANGED, 6 => ImportRow::INVALID],
            $batch->rows()->orderBy('row_number')->pluck('status', 'row_number')->all());

        $runner->import($batch, $importer);
        $result = $batch->fresh()->summary['result'];

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['duplicates']);
        $this->assertSame(1, $result['invalid']);
        $this->assertSame('Headset', Asset::query()->where('serial_number', 'HS-1')->firstOrFail()->assetType->name);
        $this->assertSame('Jabra Evolve2 40', Asset::query()->where('serial_number', 'HS-2')->firstOrFail()->assetModel->fullName());

        $this->get(ImportData::getUrl(['importer' => 'headsets', 'batch' => $batch->id]))
            ->assertSuccessful()
            ->assertSeeInOrder(['Total', '5', 'Successful', '2', 'Duplicates', '2', 'Failed', '1']);

        // And the error report lists the three that did not go in.
        $report = $runner->errorReport($batch->fresh(), $importer, 'csv');
        $this->assertCount(4, file($report->getFile()->getPathname(), FILE_SKIP_EMPTY_LINES));
    }

    public function test_the_template_is_the_shape_of_the_ops_sheet(): void
    {
        $file = app(ImportRunner::class)->template(app(HeadsetImporter::class), 'csv');
        $rows = array_values(iterator_to_array(Spreadsheet::read($file->getFile()->getPathname(), 'template.csv')));

        $this->assertSame([
            'OID', 'Name', 'Headset Model', 'Headsets S/N', 'Headset Status',
            'Cord Model', 'Cord S/N', 'Cord Status', 'Site', 'Received Date', 'Account',
        ], $rows[0]);

        // One example row, filled in the way the sheet is filled in.
        $this->assertSame('Jabra BIZ 1500 Direct USB', $rows[1][2]);
        $this->assertSame('New', $rows[1][4]);
        $this->assertSame('ALX', $rows[1][8]);
    }

    public function test_the_ops_sheet_imports_as_it_is_written(): void
    {
        $type = AssetType::factory()->headset()->create(['name' => 'Headset']);
        $site = Site::factory()->create(['name' => 'Alexandria Site', 'code' => 'ALX']);
        $sara = Employee::factory()->create(['name' => 'Sara Ali', 'oid' => '7654321']);
        Manufacturer::factory()->create(['name' => 'Jabra']);
        Asset::factory()->create(['serial_number' => 'HS-OLD', 'cord_serial' => 'CD-OLD']);

        $path = tempnam(sys_get_temp_dir(), 'hs').'.csv';
        $handle = fopen($path, 'w');

        foreach ([
            ['OID', 'Name', 'Headset Model', 'Headsets S/N', 'Headset Status', 'Cord Model', 'Cord S/N', 'Cord Status', 'Site', 'Received Date', 'Account'],
            // Stock: nobody has it, no cord, the site by its code, a new account.
            ['', '', 'Jabra BIZ 1500 Direct USB ', 'HS-0001', 'New', '-', '-', '-', 'ALX', '2026-09-07', 'Comcast'],
            // With somebody, and a cord of its own.
            ['7654321', 'Sara Ali', 'Jabra BIZ 1500 Direct USB', 'HS-0002', 'Assigned', 'Jabra QD to USB-A', 'CD-0002', 'New', 'ALX', '2026-09-07', 'Comcast'],
            // The name disagrees with the OID: a warning, and the OID decides.
            ['7654321', 'Sarah Aly', 'Jabra BIZ 1500 Direct USB', 'HS-0003', 'Damaged', '-', '-', '-', 'ALX', '2026-09-07', 'Comcast'],
            // That cord is on another row of this same file.
            ['', '', 'Jabra BIZ 1500 Direct USB', 'HS-0004', 'New', 'Jabra QD to USB-A', 'CD-0002', 'New', 'ALX', '2026-09-07', 'Comcast'],
            // A state word that means nothing here.
            ['', '', 'Jabra BIZ 1500 Direct USB', 'HS-0005', 'Ordered', '-', '-', '-', 'ALX', '2026-09-07', 'Comcast'],
            // And that cord is already on a headset in the register.
            ['', '', 'Jabra BIZ 1500 Direct USB', 'HS-0006', 'New', 'Jabra QD to USB-A', 'CD-OLD', 'New', 'ALX', '2026-09-07', 'Comcast'],
        ] as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $runner = app(ImportRunner::class);
        $importer = app(HeadsetImporter::class);
        $batch = $runner->check($importer, $path, 'New_Headsets_Ops.csv', ['headset_type_id' => $type->id], auth()->user());

        $this->assertSame(
            [2 => ImportRow::NEW, 3 => ImportRow::NEW, 4 => ImportRow::NEW, 5 => ImportRow::INVALID, 6 => ImportRow::INVALID, 7 => ImportRow::INVALID],
            $batch->rows()->orderBy('row_number')->pluck('status', 'row_number')->all(),
        );

        $messages = fn (int $number): string => collect($batch->rows()->where('row_number', $number)->value('messages'))->pluck('text')->implode(' ');

        $this->assertStringContainsString('is Sara Ali, not "Sarah Aly"', $messages(4));
        $this->assertStringContainsString('Cord S/N CD-0002 is also on another row of this file', $messages(5));
        $this->assertStringContainsString('neither a status', $messages(6));
        $this->assertStringContainsString('Cord S/N CD-OLD is already on headset HS-OLD', $messages(7));

        $runner->import($batch, $importer);

        // "Jabra BIZ 1500 Direct USB" is a Jabra, model "BIZ 1500 Direct USB".
        $stock = Asset::query()->where('serial_number', 'HS-0001')->firstOrFail();
        $this->assertSame('Jabra BIZ 1500 Direct USB', $stock->assetModel->fullName());
        $this->assertSame(1, Manufacturer::query()->where('name', 'Jabra')->count());
        // "New" says both where it is and what shape it is in.
        $this->assertSame(AssetStatus::Available, $stock->status);
        $this->assertSame(AssetCondition::New, $stock->condition);
        // A dash is nothing, not a cord called "-".
        $this->assertNull($stock->cord_model);
        $this->assertNull($stock->cord_serial);
        $this->assertNull($stock->cord_condition);
        $this->assertSame($site->id, $stock->site_id);
        $this->assertSame('Comcast', $stock->account->name);
        $this->assertSame('2026-09-07', $stock->purchase_date->toDateString());

        $assigned = Asset::query()->where('serial_number', 'HS-0002')->firstOrFail();
        $this->assertSame($sara->id, $assigned->employee_id);
        $this->assertSame('Jabra QD to USB-A', $assigned->cord_model);
        $this->assertSame('CD-0002', $assigned->cord_serial);
        $this->assertSame(AssetCondition::New, $assigned->cord_condition);

        // "Damaged" says what shape it is in and nothing about where it is, so
        // the OID decides that: somebody holds it, so it is Assigned.
        $damaged = Asset::query()->where('serial_number', 'HS-0003')->firstOrFail();
        $this->assertSame(AssetCondition::Damaged, $damaged->condition);
        $this->assertSame(AssetStatus::Assigned, $damaged->status);
        $this->assertSame($sara->id, $damaged->employee_id);

        // And a cord serial is searchable, as a serial should be.
        $this->assertTrue(Asset::query()->search('CD-0002')->get()->contains($assigned));
    }

    public function test_the_holders_name_from_the_sheet_is_treated_as_personal_data(): void
    {
        $this->assertSame(['employee_name'], app(HeadsetImporter::class)->sensitiveFields());

        $type = AssetType::factory()->headset()->create(['name' => 'Headset']);
        Employee::factory()->create(['name' => 'Sara Ali', 'oid' => '7654321']);

        $path = tempnam(sys_get_temp_dir(), 'hs').'.csv';
        file_put_contents($path, "OID,Name,Headsets S/N\n7654321,Sara Ali,HS-NAME\n");

        $batch = app(ImportRunner::class)->check(app(HeadsetImporter::class), $path, 'headsets.csv', ['headset_type_id' => $type->id], auth()->user());

        // The row waits in the database with the name encrypted.
        $stored = json_decode(DB::table('import_rows')->where('import_batch_id', $batch->id)->value('data'), true);

        $this->assertStringStartsWith('sealed:', $stored['employee_name']);
        $this->assertSame('7654321', $stored['employee_oid']);
    }

    public function test_a_cord_is_recorded_beside_the_headset_it_came_with(): void
    {
        $type = AssetType::factory()->headset()->create(['name' => 'Headset']);
        $model = AssetModel::factory()->create(['asset_type_id' => $type->id, 'name' => 'BIZ 1500 Direct USB']);

        Livewire::test(AddHeadsets::class)
            ->fillForm([
                'asset_model_id' => $model->id,
                'serial_number' => 'HS-CORD-1',
                'cord_model' => 'Jabra QD to USB-A',
                'cord_serial' => 'CD-1',
                'cord_condition' => AssetCondition::New->value,
            ])
            ->call('save', true)
            ->assertHasNoFormErrors()
            // The cord's serial belongs to that one headset, so it is cleared.
            ->assertFormSet(['cord_serial' => null, 'cord_model' => 'Jabra QD to USB-A'])
            ->fillForm(['serial_number' => 'HS-CORD-2', 'cord_serial' => 'CD-1'])
            ->call('save', true)
            ->assertHasFormErrors(['cord_serial' => 'unique']);

        $headset = Asset::query()->where('serial_number', 'HS-CORD-1')->firstOrFail();

        $this->assertSame('CD-1', $headset->cord_serial);
        $this->assertSame(AssetCondition::New, $headset->cord_condition);
        // And a cord swap is history, like everything else about an asset.
        $headset->update(['cord_serial' => 'CD-2']);
        $this->assertSame(['from' => 'CD-1', 'to' => 'CD-2'], $headset->history()->latest('id')->first()->changes['cord_serial']);
    }

    public function test_the_type_from_the_upload_screen_must_be_a_headset_type(): void
    {
        $laptop = AssetType::factory()->create(['name' => 'Laptop']);

        $path = tempnam(sys_get_temp_dir(), 'hs').'.csv';
        file_put_contents($path, "Serial Number\nHS-9\n");

        $batch = app(ImportRunner::class)->check(app(HeadsetImporter::class), $path, 'headsets.csv', ['headset_type_id' => $laptop->id], auth()->user());

        $this->assertSame(ImportRow::INVALID, $batch->rows()->value('status'));
    }
}

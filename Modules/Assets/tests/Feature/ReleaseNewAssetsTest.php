<?php

namespace Modules\Assets\Tests\Feature;

use App\Filament\Pages\ImportData;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\ImportRunner;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Assets\Actions\CheckReleaseBatch;
use Modules\Assets\Actions\ReleaseBatchAssets;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Pages\PrintReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\CreateReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\EditReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\ViewReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\RelationManagers\ItemsRelationManager;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;
use Modules\Assets\Imports\ReleaseFormImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\HandoverForm;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Tests\TestCase;

class ReleaseNewAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->engineer = User::factory()->withPermissions(
            'releases.view', 'releases.manage', 'releases.release',
            'assets.view', 'assignments.assign', 'assignments.view', 'assignments.print',
        )->create(['name' => 'Engineer One']);

        $this->actingAs($this->engineer);
    }

    protected function batch(array $items = []): ReleaseBatch
    {
        $type = AssetType::factory()->computer()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['asset_type_id' => $type->id, 'name' => 'Latitude 5440']);
        $site = Site::factory()->create(['name' => 'Alexandria']);
        $location = Location::query()->create(['site_id' => $site->id, 'name' => 'Floor 2']);

        $batch = ReleaseBatch::query()->create([
            'asset_type_id' => $type->id,
            'asset_model_id' => $model->id,
            'site_id' => $site->id,
            'location_id' => $location->id,
            'purchase_date' => '2026-09-01',
            'created_by_name' => 'Engineer One',
        ]);

        foreach ($items as $item) {
            $batch->items()->create($item);
        }

        return $batch->refresh();
    }

    public function test_the_three_checks_find_what_they_are_for(): void
    {
        Employee::factory()->create(['oid' => '1111111', 'name' => 'Ahmed']);
        Employee::factory()->left()->create(['oid' => '2222222', 'name' => 'Gone']);
        Asset::factory()->create(['serial_number' => 'OLD-SERIAL', 'asset_tag' => 'OLD-TAG']);

        $batch = $this->batch([
            ['serial_number' => 'NEW-1', 'asset_tag' => 'T-1', 'computer_name' => 'PC-1', 'employee_oid' => '1111111'],
            ['serial_number' => 'NEW-2', 'asset_tag' => 'T-1', 'computer_name' => 'PC-2', 'employee_oid' => '2222222'],
            ['serial_number' => 'NEW-2', 'computer_name' => 'PC-3', 'employee_oid' => '9999999'],
            ['serial_number' => 'OLD-SERIAL', 'asset_tag' => 'OLD-TAG'],
        ]);

        $problems = app(CheckReleaseBatch::class)->handle($batch, null, $this->engineer);

        // new: both rows sharing tag T-1, and the second row with serial NEW-2.
        $this->assertSame(['employees' => 2, 'new' => 3, 'old' => 1], $problems);

        [$first, $leaver, $twin, $old] = $batch->items()->orderBy('id')->get()->all();
        $text = fn ($item): string => collect($item->allFindings())->pluck('text')->implode(' ');

        $this->assertTrue($first->hasErrors());
        $this->assertStringContainsString('Tag T-1 is on more than one row', $text($first));
        $this->assertStringNotContainsString('OID', $text($first));
        $this->assertStringContainsString('Gone (2222222) has left', $text($leaver));
        $this->assertStringContainsString('No employee has OID 9999999', $text($twin));
        $this->assertStringContainsString('Serial NEW-2 is on more than one row', $text($twin));
        $this->assertStringContainsString('Serial OLD-SERIAL is already in the register', $text($old));
        $this->assertStringContainsString('Tag OLD-TAG is already on OLD-SERIAL', $text($old));
        $this->assertStringContainsString('goes into stock', $text($old));
        $this->assertStringContainsString('No computer name', $text($old));

        $this->assertSame('Engineer One', $batch->fresh()->checks['old']['by']);
        $this->assertFalse(app(CheckReleaseBatch::class)->passes($batch->fresh()));

        // An edited row is unchecked again.
        $old->update(['serial_number' => 'FIXED']);
        $this->assertNull($old->fresh()->findings);
    }

    public function test_releasing_creates_the_assets_hands_them_out_and_archives(): void
    {
        $ahmed = Employee::factory()->create(['oid' => '1111111', 'name' => 'Ahmed']);
        Employee::factory()->create(['oid' => '3333333', 'name' => 'Sara']);

        $batch = $this->batch([
            ['serial_number' => 'NEW-1', 'asset_tag' => 'T-1', 'computer_name' => 'PC-1', 'employee_oid' => '1111111'],
            ['serial_number' => 'NEW-2', 'computer_name' => 'PC-2', 'employee_oid' => '1111111'],
            ['serial_number' => 'NEW-3', 'computer_name' => 'PC-3', 'employee_oid' => '3333333'],
            ['serial_number' => 'NEW-4', 'computer_name' => 'PC-4'],
        ]);

        $forms = app(ReleaseBatchAssets::class)->handle($batch, $this->engineer);

        $this->assertCount(2, $forms);
        $batch->refresh();
        $this->assertTrue($batch->isArchived());
        $this->assertSame('Engineer One', $batch->released_by_name);

        $first = Asset::query()->where('serial_number', 'NEW-1')->firstOrFail();
        $this->assertSame('Laptop', $first->assetType->name);
        $this->assertSame('Floor 2', $first->location->name);
        $this->assertSame('2026-09-01', $first->purchase_date->toDateString());
        $this->assertSame($ahmed->id, $first->employee_id);
        $this->assertSame(AssetStatus::Assigned, $first->status);

        $ahmedsForm = HandoverForm::query()->where('employee_id', $ahmed->id)->firstOrFail();
        $this->assertSame(['NEW-1', 'NEW-2'], array_column($ahmedsForm->snapshot['assets'], 'serial_number'));
        $this->assertStringContainsString($batch->number, $ahmedsForm->snapshot['notes']);

        $stock = Asset::query()->where('serial_number', 'NEW-4')->firstOrFail();
        $this->assertNull($stock->employee_id);
        $this->assertSame(AssetStatus::Available, $stock->status);

        $item = $batch->items()->where('serial_number', 'NEW-1')->firstOrFail();
        $this->assertSame($first->id, $item->asset_id);
        $this->assertSame($ahmedsForm->id, $item->handover_form_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'released', 'record_label' => $batch->number]);

        // Once is enough.
        $this->expectException(ValidationException::class);
        app(ReleaseBatchAssets::class)->handle($batch, $this->engineer);
    }

    public function test_a_batch_with_problems_is_not_released_at_all_and_keeps_its_findings(): void
    {
        Asset::factory()->create(['serial_number' => 'TAKEN']);

        $batch = $this->batch([
            ['serial_number' => 'FINE-1', 'computer_name' => 'PC-1'],
            ['serial_number' => 'TAKEN', 'computer_name' => 'PC-2'],
        ]);

        try {
            app(ReleaseBatchAssets::class)->handle($batch, $this->engineer);
            $this->fail('A batch with problems should not be released.');
        } catch (ValidationException) {
        }

        $this->assertTrue($batch->fresh()->isDraft());
        $this->assertNull(Asset::query()->where('serial_number', 'FINE-1')->first());
        $this->assertTrue($batch->items()->where('serial_number', 'TAKEN')->firstOrFail()->hasErrors());
    }

    public function test_the_screens_stage_check_and_release_a_batch(): void
    {
        Employee::factory()->create(['oid' => '1111111']);
        $type = AssetType::factory()->create(['name' => 'Monitor']);

        Livewire::test(CreateReleaseBatch::class)
            ->fillForm(['asset_type_id' => $type->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $batch = ReleaseBatch::query()->firstOrFail();
        $this->assertSame('Engineer One', $batch->created_by_name);
        $this->assertMatchesRegularExpression('/^RB-\d{4}-\d{5}$/', $batch->number);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => EditReleaseBatch::class])
            ->callTableAction('create', data: ['serial_number' => 'MON-1', 'employee_oid' => '1111111', 'condition' => 'new'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('paste', data: ['lines' => "Serial\tTag\tComputer\tOID\nMON-2\tT-2\t\t\n\nMON-3,T-3,,1111111\n\t\t\t"])
            ->assertNotified();

        $this->assertSame(['MON-1', 'MON-2', 'MON-3'], $batch->items()->orderBy('id')->pluck('serial_number')->all());
        $this->assertSame('1111111', $batch->items()->where('serial_number', 'MON-3')->value('employee_oid'));

        Livewire::test(EditReleaseBatch::class, ['record' => $batch->id])
            ->callAction('checkAll')
            ->assertNotified('No problems found')
            ->callAction('release')
            ->assertRedirect(ReleaseBatchResource::getUrl('view', ['record' => $batch]));

        $this->assertTrue($batch->fresh()->isArchived());
        $this->assertSame(3, Asset::query()->count());

        // Archived: read-only, and the edit page is refused.
        $this->assertFalse($this->engineer->can('update', $batch->fresh()));
        $this->get(ReleaseBatchResource::getUrl('edit', ['record' => $batch]))->assertForbidden();
        $this->get(ReleaseBatchResource::getUrl('view', ['record' => $batch]))->assertSuccessful()->assertSee($batch->number);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch->fresh(), 'pageClass' => ViewReleaseBatch::class])
            ->assertCanSeeTableRecords($batch->items()->get())
            ->assertSee('HO-')
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('paste');
    }

    public function test_quick_print_records_nothing_and_a_reprint_is_counted(): void
    {
        Employee::factory()->create(['oid' => '1111111', 'name' => 'Ahmed Hassan']);
        $batch = $this->batch([['serial_number' => 'NEW-1', 'computer_name' => 'PC-1', 'employee_oid' => '1111111']]);
        $url = PrintReleaseBatch::getUrl(['batch' => $batch->id]);

        $this->get($url)
            ->assertSuccessful()
            ->assertSee('New Data — Quick Print')
            ->assertSee('NOT RELEASED')
            ->assertSee('Ahmed Hassan');

        $this->assertSame(0, $batch->fresh()->print_count);

        app(ReleaseBatchAssets::class)->handle($batch, $this->engineer);

        $this->get($url)->assertSuccessful()->assertSee('New Data Report')->assertDontSee('NOT RELEASED')->assertSee('HO-');
        $this->get($url)->assertSee('Copy 2');

        $this->assertSame(2, $batch->fresh()->print_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reprinted', 'record_label' => $batch->number]);
    }

    public function test_the_release_form_template_is_the_shape_of_the_form(): void
    {
        $file = app(ImportRunner::class)->template(app(ReleaseFormImporter::class), 'csv');
        $rows = array_values(iterator_to_array(Spreadsheet::read($file->getFile()->getPathname(), 'template.csv')));

        $this->assertSame([
            'Serial', 'Employee_ID', 'Employee_Name', 'Mobile_No', 'Laptop_Name', 'Laptop_Model', 'RAM',
            'Laptop_Service_Tag', 'Laptop_Owner', 'Account', 'LOB', 'Site', 'Delivery_Date',
            'Emergency_Contact1', 'Emergency_Contact2',
        ], $rows[0]);
    }

    public function test_a_release_form_fills_a_draft_with_its_rows(): void
    {
        $batch = $this->batch();
        $batch->assetModel->manufacturer->update(['name' => 'Dell']);
        Employee::factory()->create(['oid' => '7654321', 'name' => 'Sara Ali', 'mobile' => '01012345678']);
        Asset::factory()->create(['serial_number' => 'OLD-1']);

        $path = tempnam(sys_get_temp_dir(), 'rf').'.csv';
        $handle = fopen($path, 'w');

        foreach ([
            ['Serial', 'Employee_ID', 'Employee_Name', 'Mobile_No', 'Laptop_Name', 'Laptop_Model', 'RAM', 'Laptop_Service_Tag', 'Laptop_Owner', 'Account', 'LOB', 'Site', 'Delivery_Date', 'Emergency_Contact1', 'Emergency_Contact2'],
            ['1', '7654321', 'Sara Ali', '01012345678', 'ALX-LT-0001', 'Dell Latitude 5440', '16 GB', 'NEW-1', 'Concentrix', '', 'Customer Care', 'Alexandria', '2026-09-24', 'Mona Ali 01098765432', ''],
            // No OID: into stock. And "Serial" is the line number, not a serial.
            ['2', '', '', '', 'ALX-LT-0002', 'Dell Latitude 5440', '8 GB', 'NEW-2', 'Concentrix', '', 'Customer Care', 'Alexandria', '2026-09-24', '', ''],
            // A name that disagrees with the OID, and a mobile that does too.
            ['3', '7654321', 'Sarah Aly', '01000000000', 'ALX-LT-0003', 'Dell Latitude 5440', '16 GB', 'NEW-3', 'Concentrix', '', 'Customer Care', 'Alexandria', '2026-09-24', '', ''],
            // Nobody has that OID, and the site is not the release's.
            ['4', '9999999', 'Nobody', '', 'ALX-LT-0004', 'Dell Latitude 5440', '16 GB', 'NEW-4', 'Concentrix', '', 'Customer Care', 'Cairo', '2026-09-24', '', ''],
            // Already in the register.
            ['5', '', '', '', 'ALX-LT-0005', 'Dell Latitude 5440', '16 GB', 'OLD-1', 'Concentrix', '', 'Customer Care', 'Alexandria', '2026-09-24', '', ''],
        ] as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $runner = app(ImportRunner::class);
        $importer = app(ReleaseFormImporter::class);
        $checked = $runner->check($importer, $path, 'Release Data Form.csv', ['release_batch_id' => $batch->id], $this->engineer);

        $this->assertSame(
            [2 => ImportRow::NEW, 3 => ImportRow::NEW, 4 => ImportRow::NEW, 5 => ImportRow::INVALID, 6 => ImportRow::INVALID],
            $checked->rows()->orderBy('row_number')->pluck('status', 'row_number')->all(),
        );

        $messages = fn (int $number): string => collect($checked->rows()->where('row_number', $number)->value('messages'))->pluck('text')->implode(' ');

        $this->assertStringContainsString('goes into stock', $messages(3));
        $this->assertStringContainsString('is Sara Ali, not "Sarah Aly"', $messages(4));
        $this->assertStringContainsString('mobile on record is 01012345678', $messages(4));
        $this->assertStringContainsString('No employee has OID 9999999', $messages(5));
        $this->assertStringContainsString('Site on this release is Alexandria', $messages(5));
        $this->assertStringContainsString('Serial OLD-1 is already in the register', $messages(6));
        // The employee's own details are personal data and wait encrypted.
        $stored = json_decode(DB::table('import_rows')->where('import_batch_id', $checked->id)->orderBy('row_number')->value('data'), true);

        $this->assertStringStartsWith('sealed:', $stored['employee_name']);
        $this->assertStringStartsWith('sealed:', $stored['employee_mobile']);
        $this->assertStringStartsWith('sealed:', $stored['emergency_contact_1']);
        $this->assertSame('7654321', $stored['employee_oid']);

        $runner->import($checked, $importer);

        $this->assertSame(3, $batch->items()->count());

        $first = $batch->items()->where('serial_number', 'NEW-1')->firstOrFail();
        $this->assertSame('7654321', $first->employee_oid);
        $this->assertSame('ALX-LT-0001', $first->computer_name);
        $this->assertSame('16 GB', $first->ram);
        $this->assertSame('Concentrix', $first->owner);
        $this->assertSame('Customer Care', $first->lob);
        $this->assertSame('2026-09-24', $first->delivery_date->toDateString());
        $this->assertNull($batch->items()->where('serial_number', 'NEW-2')->value('employee_oid'));

        // The rows are the batch's own new data, so the checks and the release
        // work on them as if they had been typed in.
        $problems = app(CheckReleaseBatch::class)->handle($batch->fresh());
        $this->assertSame(['employees' => 0, 'new' => 0, 'old' => 0], $problems);

        // And a corrected form re-uploaded onto the same release updates its rows.
        $again = tempnam(sys_get_temp_dir(), 'rf').'.csv';
        file_put_contents($again, "Laptop_Service_Tag,RAM\nNEW-1,32 GB\n");

        $second = $runner->check($importer, $again, 'again.csv', ['release_batch_id' => $batch->id], $this->engineer);
        $runner->import($second, $importer);

        $this->assertSame(3, $batch->items()->count());
        $this->assertSame('32 GB', $batch->items()->where('serial_number', 'NEW-1')->value('ram'));
    }

    public function test_the_upload_only_ever_fills_a_draft_somebody_may_work_on(): void
    {
        $batch = $this->batch([['serial_number' => 'NEW-9']]);

        // The batch screen sends people to the import with the release filled in.
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => EditReleaseBatch::class])
            ->assertActionHasUrl(TestAction::make('upload')->table(), ImportData::getUrl([
                'importer' => 'release-form',
                'options' => ['release_batch_id' => $batch->id],
            ]));

        // And the import screen opens with that release already chosen.
        Livewire::test(ImportData::class, ['importer' => 'release-form', 'options' => ['release_batch_id' => $batch->id]])
            ->assertFormSet(['release_batch_id' => $batch->id]);

        $path = tempnam(sys_get_temp_dir(), 'rf').'.csv';
        file_put_contents($path, "Laptop_Service_Tag\nNEW-10\n");

        // An archived batch is a record: its rows cannot be added to.
        app(ReleaseBatchAssets::class)->handle($batch, $this->engineer);
        $this->assertTrue($batch->fresh()->isArchived());

        $batch = $batch->fresh();
        $checked = app(ImportRunner::class)->check(app(ReleaseFormImporter::class), $path, 'form.csv', ['release_batch_id' => $batch->id], $this->engineer);

        $this->assertSame(ImportRow::INVALID, $checked->rows()->value('status'));
        $this->assertStringContainsString('Choose a draft release', collect($checked->rows()->value('messages'))->pluck('text')->implode(' '));

        // And somebody who may not stage a release cannot run the import at all.
        $viewer = User::factory()->withPermissions('releases.view', 'assets.view')->create();
        $this->assertFalse(app(ReleaseFormImporter::class)->authorize($viewer));
        $this->assertTrue(app(ReleaseFormImporter::class)->authorize($this->engineer));
    }

    public function test_releasing_needs_its_own_permission_and_assigning(): void
    {
        $batch = $this->batch([['serial_number' => 'NEW-1', 'computer_name' => 'PC-1']]);

        $stager = User::factory()->withPermissions('releases.view', 'releases.manage', 'assets.view')->create();
        $this->assertTrue($stager->can('update', $batch));
        $this->assertFalse($stager->can('release', $batch));

        $noAssign = User::factory()->withPermissions('releases.view', 'releases.release')->create();
        $this->assertFalse($noAssign->can('release', $batch));

        $this->actingAs(User::factory()->withPermissions('assets.view')->create())
            ->get(ReleaseBatchResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($stager);
        Livewire::test(EditReleaseBatch::class, ['record' => $batch->id])->assertActionHidden('release');
    }
}

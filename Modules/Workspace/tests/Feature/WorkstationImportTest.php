<?php

namespace Modules\Workspace\Tests\Feature;

use App\Filament\Pages\ImportData;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\ImportFileException;
use App\Support\Import\ImportRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Imports\WorkstationImporter;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkstationImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Floor $floor;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);

        $this->floor = Floor::factory()->for(Building::factory()->create(['name' => 'HQ Tower B']))->create(['name' => 'Floor 2']);
    }

    /** @param  list<list<string>>  $rows */
    protected function csv(array $rows, string $separator = ','): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, $separator);
        }

        fclose($handle);

        return $path;
    }

    /** @param  list<list<string>>  $rows */
    protected function check(array $rows, array $options = []): ImportBatch
    {
        return app(ImportRunner::class)->check(
            app(WorkstationImporter::class),
            $this->csv($rows),
            'desks.csv',
            $options,
            $this->admin,
        );
    }

    protected function importBatch(ImportBatch $batch): ImportBatch
    {
        return app(ImportRunner::class)->import($batch, app(WorkstationImporter::class));
    }

    /** @return array<int, string> row number => status */
    protected function statuses(ImportBatch $batch): array
    {
        return $batch->rows()->orderBy('row_number')->pluck('status', 'row_number')->all();
    }

    private const HEADINGS = ['Floor', 'Workstation ID', 'Area / Zone', 'Switch', 'Port', 'Rack', 'VLAN', 'PC Name', 'IP Address', 'MAC Address', 'Status'];

    public function test_a_clean_sheet_is_previewed_without_writing_anything(): void
    {
        $batch = $this->check([
            self::HEADINGS,
            ['Floor 2', 'WS-001', 'Operations Floor', 'SW-02', 'Gi2/0/1', 'RACK-02', '123', 'ALX-PC-001', '10.20.30.1', 'aa-bb-cc-dd-ee-01', 'Active'],
            ['Floor 2', 'WS-002', 'Operations Floor', 'SW-02', 'Gi2/0/2', 'RACK-02', '123', 'ALX-PC-002', '10.20.30.2', 'aabbccddee02', ''],
        ]);

        $this->assertSame([2 => ImportRow::NEW, 3 => ImportRow::NEW], $this->statuses($batch));
        $this->assertSame(2, $batch->count('new'));

        // A preview is a promise, not a write.
        $this->assertSame(0, Workstation::query()->count());
        $this->assertSame(0, Area::query()->count());
        $this->assertSame(0, NetworkSwitch::query()->count());

        $row = $batch->rows()->first();
        $this->assertContains('Area "Operations Floor" will be created on Floor 2.', collect($row->messages)->pluck('text')->all());
    }

    public function test_importing_creates_the_desks_and_everything_they_name(): void
    {
        $batch = $this->importBatch($this->check([
            self::HEADINGS,
            ['Floor 2', 'WS-001', 'Operations Floor', 'SW-02', 'Gi2/0/1', 'RACK-02', '123', 'ALX-PC-001', '10.20.30.1', 'aa-bb-cc-dd-ee-01', 'Under Maintenance'],
            ['Floor 2', 'WS-002', 'Operations Floor', 'SW-02', 'Gi2/0/2', 'RACK-02', '123', 'ALX-PC-002', '10.20.30.2', 'aabbccddee02', ''],
        ]));

        $this->assertSame(2, $batch->summary['result']['imported']);

        $desk = Workstation::query()->where('name', 'WS-001')->firstOrFail();

        $this->assertSame($this->floor->id, $desk->floor_id);
        $this->assertSame('Operations Floor', $desk->area->name);
        $this->assertSame('SW-02', $desk->networkSwitch()->number);
        $this->assertSame('Gi2/0/1', $desk->switchPort->name);
        $this->assertSame('RACK-02', $desk->rack()->number);
        $this->assertSame(123, $desk->vlan->number);
        $this->assertSame('AA:BB:CC:DD:EE:01', $desk->mac_address);
        $this->assertSame(WorkstationStatus::UnderMaintenance, $desk->status);

        // An empty status on a new desk means Active.
        $this->assertSame(WorkstationStatus::Active, Workstation::query()->where('name', 'WS-002')->value('status'));

        // One area, one switch, one rack, one VLAN — shared, not one per row.
        $this->assertSame(1, Area::query()->count());
        $this->assertSame(1, NetworkSwitch::query()->count());
        $this->assertSame(1, Vlan::query()->count());
    }

    public function test_bad_rows_are_caught_and_left_out(): void
    {
        $batch = $this->check([
            self::HEADINGS,
            ['Floor 9', 'WS-001', '', '', '', '', '', '', '', '', ''],
            ['Floor 2', '', '', '', '', '', '', '', '', '', ''],
            ['Floor 2', 'WS-003', '', '', '', '', '5000', '', '', '', ''],
            ['Floor 2', 'WS-004', '', '', '', '', '', '', '999.1.1.1', 'not-a-mac', 'Broken'],
            ['Floor 2', 'WS-005', '', '', 'Gi1/0/5', '', '', '', '', '', ''],
            ['Floor 2', 'WS-006', '', '', '', '', '', '', '', '', ''],
        ]);

        $this->assertSame([
            2 => ImportRow::INVALID, // no such floor
            3 => ImportRow::INVALID, // no workstation id
            4 => ImportRow::INVALID, // VLAN out of range
            5 => ImportRow::INVALID, // bad IP, MAC and status
            6 => ImportRow::INVALID, // port without a switch
            7 => ImportRow::NEW,
        ], $this->statuses($batch));

        $errors = $batch->rows()->where('row_number', 5)->firstOrFail()->errors();
        $this->assertCount(3, $errors);

        $this->importBatch($batch);

        $this->assertSame(['WS-006'], Workstation::query()->pluck('name')->all());
    }

    public function test_duplicates_inside_the_file_are_flagged(): void
    {
        $batch = $this->check([
            ['Floor', 'Workstation ID'],
            ['Floor 2', 'WS-001'],
            ['floor 2', 'ws-001'],
        ]);

        $this->assertSame([2 => ImportRow::NEW, 3 => ImportRow::DUPLICATE], $this->statuses($batch));
        $this->assertSame(['Same record as row 2 of this file.'], $batch->rows()->where('row_number', 3)->firstOrFail()->errors());
    }

    /** One port, one desk — in the file and against what is already recorded. */
    public function test_a_port_claimed_twice_is_refused(): void
    {
        $switch = NetworkSwitch::factory()->create(['building_id' => $this->floor->building_id, 'number' => 'SW-02']);
        $port = SwitchPort::factory()->create(['network_switch_id' => $switch->id, 'name' => 'Gi2/0/9']);
        Workstation::factory()->for($this->floor)->create(['name' => 'WS-100', 'switch_port_id' => $port->id]);

        $batch = $this->check([
            ['Floor', 'Workstation ID', 'Switch', 'Port'],
            ['Floor 2', 'WS-001', 'SW-02', 'Gi2/0/9'],
            ['Floor 2', 'WS-002', 'SW-02', 'Gi2/0/1'],
            ['Floor 2', 'WS-003', 'SW-02', 'Gi2/0/1'],
        ]);

        $this->assertSame([2 => ImportRow::INVALID, 3 => ImportRow::NEW, 4 => ImportRow::INVALID], $this->statuses($batch));
        $this->assertStringContainsString('already patched to Floor 2 · WS-100', $batch->rows()->where('row_number', 2)->firstOrFail()->errors()[0]);
    }

    public function test_existing_desks_are_left_alone_unless_asked_to_update(): void
    {
        Workstation::factory()->for($this->floor)->create(['name' => 'WS-001', 'computer_name' => 'OLD-PC', 'ip_address' => '10.0.0.1']);

        $rows = [
            ['Floor', 'Workstation ID', 'PC Name', 'IP Address'],
            ['Floor 2', 'WS-001', 'NEW-PC', ''],
        ];

        $skip = $this->importBatch($this->check($rows));
        $this->assertSame(1, $skip->summary['result']['skipped']);
        $this->assertSame('OLD-PC', Workstation::query()->value('computer_name'));

        $update = $this->importBatch($this->check($rows, ['existing' => ImportRunner::EXISTING_UPDATE]));
        $this->assertSame(1, $update->summary['result']['updated']);

        $desk = Workstation::query()->firstOrFail();
        $this->assertSame('NEW-PC', $desk->computer_name);
        // An empty cell does not wipe what was there.
        $this->assertSame('10.0.0.1', $desk->ip_address);
    }

    public function test_with_creation_off_names_that_do_not_exist_are_invalid(): void
    {
        $batch = $this->check([
            ['Floor', 'Workstation ID', 'Area', 'Switch', 'VLAN'],
            ['Floor 2', 'WS-001', 'Nowhere', 'SW-99', '777'],
        ], ['create_missing' => false]);

        $this->assertSame([2 => ImportRow::INVALID], $this->statuses($batch));
        $this->assertCount(3, $batch->rows()->firstOrFail()->errors());
    }

    public function test_a_floor_name_used_in_two_buildings_needs_the_building_column(): void
    {
        Floor::factory()->for(Building::factory()->create(['name' => 'Annex']))->create(['name' => 'Floor 2']);

        $ambiguous = $this->check([['Floor', 'Workstation ID'], ['Floor 2', 'WS-001']]);
        $this->assertSame([2 => ImportRow::INVALID], $this->statuses($ambiguous));

        $clear = $this->check([['Building', 'Floor', 'Workstation ID'], ['Annex', 'Floor 2', 'WS-001']]);
        $this->assertSame([2 => ImportRow::NEW], $this->statuses($clear));
    }

    public function test_headings_are_recognised_however_they_are_written(): void
    {
        $batch = $this->check([
            ['floor name', 'WS ID', 'Computer Name', 'mac', 'Some Column We Do Not Know'],
            ['Floor 2', 'WS-001', 'ALX-PC-001', 'AABBCCDDEE01', 'whatever'],
        ]);

        $this->assertSame([2 => ImportRow::NEW], $this->statuses($batch));
        $this->assertSame(['Some Column We Do Not Know'], $batch->summary['ignored_headings']);
    }

    public function test_a_file_missing_a_required_column_is_refused_whole(): void
    {
        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('missing required column(s): Workstation ID');

        $this->check([['Floor', 'PC Name'], ['Floor 2', 'ALX-PC-001']]);
    }

    /** Excel saves "CSV" with semicolons in much of the world. */
    public function test_a_semicolon_separated_file_reads_the_same(): void
    {
        $batch = app(ImportRunner::class)->check(
            app(WorkstationImporter::class),
            $this->csv([['Floor', 'Workstation ID', 'PC Name'], ['Floor 2', 'WS-001', 'ALX-PC-001']], ';'),
            'desks.csv',
            [],
            $this->admin,
        );

        $this->assertSame([2 => ImportRow::NEW], $this->statuses($batch));
        $this->assertSame('ALX-PC-001', $batch->rows()->firstOrFail()->data['computer_name']);
    }

    /** Rows are checked again at import: somebody may have changed things since the preview. */
    public function test_a_row_that_became_a_conflict_after_the_preview_is_not_imported(): void
    {
        $batch = $this->check([['Floor', 'Workstation ID'], ['Floor 2', 'WS-001']]);

        Workstation::factory()->for($this->floor)->create(['name' => 'WS-001']);

        $result = $this->importBatch($batch)->summary['result'];

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Workstation::query()->count());
    }

    public function test_a_batch_cannot_be_imported_twice(): void
    {
        $batch = $this->importBatch($this->check([['Floor', 'Workstation ID'], ['Floor 2', 'WS-001']]));

        $this->expectException(HttpException::class);

        $this->importBatch($batch);
    }

    // ---- the screen ----------------------------------------------------

    public function test_the_whole_flow_through_the_import_screen(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('patching sheet.csv', implode("\n", [
            'Floor,Workstation ID,PC Name',
            'Floor 2,WS-001,ALX-PC-001',
            'Floor 9,WS-002,ALX-PC-002',
        ]));

        $page = Livewire::test(ImportData::class, ['importer' => 'workstations'])
            ->fillForm(['file' => $file, 'existing' => ImportRunner::EXISTING_SKIP])
            ->call('checkFile')
            ->assertHasNoFormErrors();

        $batch = ImportBatch::query()->firstOrFail();

        $page->assertSet('batch', $batch->id)
            ->assertCanSeeTableRecords($batch->rows)
            ->assertActionVisible('import')
            ->assertActionVisible('errorReport')
            ->callAction('import');

        $this->assertSame(['WS-001'], Workstation::query()->pluck('name')->all());
        $this->assertSame(ImportBatch::IMPORTED, $batch->refresh()->status);

        $page->callAction('errorReport')->assertFileDownloaded('patching sheet - problems.xlsx');

        // The upload itself is not kept once its rows are stored.
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_the_template_downloads_with_every_heading(): void
    {
        Livewire::test(ImportData::class, ['importer' => 'workstations'])
            ->callAction('template_csv')
            ->assertFileDownloaded('Workstations import template.csv');
    }

    public function test_importing_needs_its_own_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view', 'workstations.create')->create());

        $this->get(ImportData::getUrl(['importer' => 'workstations']))->assertForbidden();

        Livewire::test(ListWorkstations::class)->assertActionHidden('import');

        $this->actingAs(User::factory()->withPermissions('workstations.view', 'workstations.create', 'workstations.import')->create());

        $this->get(ImportData::getUrl(['importer' => 'workstations']))->assertSuccessful();
    }

    public function test_an_unknown_importer_is_not_found(): void
    {
        $this->get(ImportData::getUrl(['importer' => 'nonsense']))->assertNotFound();
    }

    /** Somebody else's upload may hold data you are not meant to see. */
    public function test_another_users_batch_cannot_be_opened(): void
    {
        $batch = $this->check([['Floor', 'Workstation ID'], ['Floor 2', 'WS-001']]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(ImportData::getUrl(['importer' => 'workstations', 'batch' => $batch->id]))->assertNotFound();
    }
}

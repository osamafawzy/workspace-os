<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\ImportRunner;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Workspace\Exports\WorkstationExport;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Imports\WorkstationImporter;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class WorkstationExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    }

    /** @return list<list<string>> */
    protected function readBack(string $path, string $name): array
    {
        return array_values(iterator_to_array(Spreadsheet::read($path, $name)));
    }

    public function test_the_list_exports_what_it_is_showing(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->wired()->create(['name' => 'WS-001']);

        Livewire::test(ListWorkstations::class)
            ->callAction('export', data: ['format' => 'csv'])
            ->assertFileDownloaded();
    }

    /**
     * An export is an import file: correct it in Excel, import it back with
     * "update existing", and nothing that was not corrected changes.
     */
    public function test_an_export_imports_back_as_the_same_desks(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->wired()->count(3)->create();
        $before = Workstation::query()->orderBy('id')->get()->map->only(Workstation::editableDetails())->all();

        foreach (['csv', 'xlsx'] as $format) {
            $response = WorkstationExport::download(Workstation::query(), $format);
            $path = $response->getFile()->getPathname();

            $batch = app(ImportRunner::class)->check(app(WorkstationImporter::class), $path, "export.{$format}", [
                'existing' => ImportRunner::EXISTING_UPDATE,
            ], $this->admin);

            $this->assertSame(3, $batch->count(ImportRow::UPDATE), $format);
            $this->assertSame(0, $batch->count(ImportRow::INVALID), $format);

            app(ImportRunner::class)->import($batch, app(WorkstationImporter::class));
        }

        $this->assertSame($before, Workstation::query()->orderBy('id')->get()->map->only(Workstation::editableDetails())->all());
        $this->assertSame(3, Workstation::query()->count());
    }

    /** A note typed as a formula must open in Excel as text, not run. */
    public function test_a_formula_in_a_cell_is_exported_as_text_and_imports_back_unchanged(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->for($floor)->create(['name' => 'WS-001', 'notes' => '=HYPERLINK("http://evil","click")']);

        $response = WorkstationExport::download(Workstation::query(), 'csv');
        $rows = $this->readBack($response->getFile()->getPathname(), 'export.txt');

        $raw = file_get_contents($response->getFile()->getPathname());
        $this->assertStringContainsString("'=HYPERLINK", $raw);

        // Reading strips the guard again.
        $notes = array_search('Notes', $rows[0], true);
        $this->assertSame('=HYPERLINK("http://evil","click")', $rows[1][$notes]);
    }

    public function test_exporting_needs_its_own_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view')->create());

        Livewire::test(ListWorkstations::class)->assertActionHidden('export');
    }
}

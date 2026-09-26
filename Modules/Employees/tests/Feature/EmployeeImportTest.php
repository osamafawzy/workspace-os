<?php

namespace Modules\Employees\Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\ImportRunner;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Exports\EmployeeExport;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use Modules\Employees\Imports\EmployeeImporter;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    /** Headings the way a Workday worker report writes them. */
    private const WORKDAY = [
        'Employee OID', 'Worker', 'Employee ID', 'Work Email', 'Mobile Phone', 'Supervisory Organization',
        'Job Profile', 'Program', 'Site', 'Work Location', 'Worker Status', 'Hire Date', 'Termination Date',
        'National ID Number', 'Home Address', 'Primary Emergency Contact', 'Emergency Contact Relationship', 'Emergency Contact Phone',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function actAs(bool $sensitive): User
    {
        $permissions = ['employees.view', 'employees.create', 'employees.update', 'employees.import', 'employees.export'];

        $user = User::factory()->withPermissions(...($sensitive ? [...$permissions, 'employees.view_sensitive'] : $permissions))->create();
        $this->actingAs($user);

        return $user;
    }

    /** @param  list<list<string>>  $rows */
    protected function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emp').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    /** @param  list<list<string>>  $rows */
    protected function check(array $rows, array $options = []): ImportBatch
    {
        return app(ImportRunner::class)->check(app(EmployeeImporter::class), $this->csv($rows), 'workday.csv', $options, auth()->user());
    }

    protected function import(ImportBatch $batch): ImportBatch
    {
        return app(ImportRunner::class)->import($batch, app(EmployeeImporter::class));
    }

    /** @return array<int, string> */
    protected function statuses(ImportBatch $batch): array
    {
        return $batch->rows()->orderBy('row_number')->pluck('status', 'row_number')->all();
    }

    public function test_a_workday_report_is_checked_then_imported(): void
    {
        $this->actAs(sensitive: true);

        $site = Site::factory()->create(['name' => 'Alexandria']);
        Location::query()->create(['site_id' => $site->id, 'name' => 'Floor 2']);

        $batch = $this->check([
            self::WORKDAY,
            ['1234567', 'Ahmed Hassan', 'EMP1', 'ahmed@example.com', '+20 10 1', 'Operations', 'Advisor', 'Telecom A', 'Alexandria', 'Floor 2', 'Active', '01/03/2024', '', '2900 1011234567', '12 Example St', 'Mona Hassan', 'Mother', '+20 11 1'],
            ['7654321', 'Sara Ali', 'EMP2', 'sara@example.com', '', 'Operations', 'Team Leader', '', 'Alexandria', '', 'Terminated', '2021-06-15', '15-Jan-2025', '', '', '', '', ''],
            ['1234567', 'Ahmed Again', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', 'No OID', '', 'not-an-email', '', '', '', '', '', '', 'Maybe', '31/31/2024', '', '', '', '', '', ''],
        ]);

        $this->assertSame([2 => ImportRow::NEW, 3 => ImportRow::NEW, 4 => ImportRow::DUPLICATE, 5 => ImportRow::INVALID], $this->statuses($batch));
        $this->assertSame(0, Employee::query()->count());

        $invalid = $batch->rows()->where('row_number', 5)->firstOrFail();
        $this->assertStringContainsString('OID is empty', implode(' ', $invalid->errors()));
        $this->assertStringContainsString('not an email', implode(' ', $invalid->errors()));
        $this->assertStringContainsString('Status "Maybe"', implode(' ', $invalid->errors()));
        $this->assertStringContainsString('Joining date "31/31/2024" is not a date', implode(' ', $invalid->errors()));

        // A department that does not exist yet is created — and said so first.
        $this->assertStringContainsString('Department "Operations" will be created', implode(' ', $batch->rows()->where('row_number', 2)->first()->warnings()));

        $this->import($batch);

        $ahmed = Employee::query()->where('oid', '1234567')->firstOrFail();
        $sara = Employee::query()->where('oid', '7654321')->firstOrFail();

        $this->assertSame('Ahmed Hassan', $ahmed->name);
        $this->assertSame('2024-03-01', $ahmed->joined_at->toDateString());
        $this->assertSame('2900 1011234567', $ahmed->national_id);
        $this->assertSame('Operations', $ahmed->department->name);
        $this->assertSame('Telecom A', $ahmed->account->name);
        $this->assertSame('Floor 2', $ahmed->location->name);
        $this->assertSame('Mona Hassan', $ahmed->emergencyContacts()->where('slot', 1)->value('name'));

        $this->assertSame(EmployeeStatus::Left, $sara->status);
        $this->assertSame('2025-01-15', $sara->left_at->toDateString());
        $this->assertSame(1, Department::query()->where('name', 'Operations')->count());
    }

    public function test_personal_data_waits_encrypted_between_check_and_import(): void
    {
        $this->actAs(sensitive: true);

        $batch = $this->check([
            ['OID', 'Name', 'National ID', 'Address', 'Emergency Contact 1 Name', 'Emergency Contact 1 Phone'],
            ['1234567', 'Ahmed Hassan', '29001011234567', '12 Secret Street', 'Mona Hassan', '+20 11 5555 0000'],
        ]);

        $stored = (string) DB::table('import_rows')->where('import_batch_id', $batch->id)->value('data');

        foreach (['29001011234567', 'Secret Street', 'Mona Hassan', '5555'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }

        $this->assertStringContainsString('Ahmed Hassan', $stored);

        $this->import($batch);

        $employee = Employee::query()->where('oid', '1234567')->firstOrFail();
        $this->assertSame('29001011234567', $employee->national_id);
        $this->assertSame('12 Secret Street', $employee->address);
        $this->assertSame('+20 11 5555 0000', $employee->emergencyContacts()->value('phone'));
    }

    public function test_somebody_who_may_not_see_personal_data_cannot_import_it(): void
    {
        $this->actAs(sensitive: false);

        $batch = $this->check([
            ['OID', 'Name', 'National ID', 'Address', 'Emergency Contact 1 Name'],
            ['1234567', 'Ahmed Hassan', '29001011234567', '12 Secret Street', 'Mona Hassan'],
        ]);

        $row = $batch->rows()->firstOrFail();
        $this->assertSame(ImportRow::NEW, $row->status);
        $this->assertStringContainsString('are not imported', implode(' ', $row->warnings()));

        $this->import($batch);

        $employee = Employee::query()->where('oid', '1234567')->firstOrFail();
        $this->assertNull($employee->national_id);
        $this->assertNull($employee->address);
        $this->assertSame(0, $employee->emergencyContacts()->count());
    }

    public function test_the_error_report_and_preview_keep_personal_data_from_those_who_may_not_see_it(): void
    {
        $owner = $this->actAs(sensitive: true);

        $batch = $this->check([
            ['OID', 'Name', 'Email', 'National ID', 'Address'],
            ['', 'Broken Row', 'nope', '29001011234567', '12 Secret Street'],
        ]);

        $read = function () use ($batch): array {
            $response = app(ImportRunner::class)->errorReport($batch, app(EmployeeImporter::class), 'csv');

            return array_values(iterator_to_array(Spreadsheet::read($response->getFile()->getPathname(), 'x.csv')));
        };

        // The person who imported it may see it, and gets it back to fix.
        $this->assertContains('29001011234567', $read()[1]);

        $this->actAs(sensitive: false);
        $report = $read();
        $this->assertNotContains('National ID', $report[0]);
        $this->assertStringNotContainsString('Secret Street', json_encode($report));

        $this->actingAs($owner)
            ->get('/admin/import?importer=employees&batch='.$batch->id)
            ->assertSuccessful()
            ->assertSee('Broken Row')
            ->assertDontSee('29001011234567')
            ->assertDontSee('Secret Street');
    }

    public function test_a_second_import_updates_by_oid_and_leaves_empty_cells_alone(): void
    {
        $this->actAs(sensitive: true);

        $employee = Employee::factory()->withPersonalDetails()->create(['oid' => '1234567', 'name' => 'Ahmed Hassan', 'mobile' => '+20 10 1', 'national_id' => '29001011234567']);

        $batch = $this->check([
            ['OID', 'Name', 'Mobile', 'Job Title', 'National ID'],
            ['1234567', 'Ahmed M. Hassan', '', 'Team Leader', ''],
        ]);

        $this->assertSame([2 => ImportRow::UPDATE], $this->statuses($batch));

        $this->import($batch);
        $employee->refresh();

        $this->assertSame('Ahmed M. Hassan', $employee->name);
        $this->assertSame('Team Leader', $employee->job_title);
        $this->assertSame('+20 10 1', $employee->mobile);
        $this->assertSame('29001011234567', $employee->national_id);
        $this->assertSame(2, $employee->emergencyContacts()->count());
    }

    public function test_a_national_id_already_on_somebody_else_is_refused_without_repeating_it(): void
    {
        $this->actAs(sensitive: true);

        Employee::factory()->create(['oid' => '1111111', 'name' => 'Existing Person', 'national_id' => '29001011234567']);

        $batch = $this->check([
            ['OID', 'Name', 'National ID'],
            ['2222222', 'New Person', '2900-1011-234567'],
        ]);

        $row = $batch->rows()->firstOrFail();
        $this->assertSame(ImportRow::INVALID, $row->status);
        $this->assertStringContainsString('already belongs to Existing Person', implode(' ', $row->errors()));
        $this->assertStringNotContainsString('234567', implode(' ', $row->errors()));
    }

    public function test_dates_are_read_however_the_export_wrote_them(): void
    {
        $importer = app(EmployeeImporter::class);

        foreach (['2024-03-01', '01/03/2024', '1/3/2024', '01-03-2024', '01.03.2024', '01-Mar-2024', '1 Mar 2024', 'Mar 01, 2024', '45352', '2024-03-01 00:00:00'] as $written) {
            $this->assertSame('2024-03-01', $importer->parseDate($written)?->toDateString(), "Reading {$written}");
        }

        $this->assertNull($importer->parseDate('31/31/2024'));
        $this->assertNull($importer->parseDate('yesterday'));
    }

    public function test_the_export_leaves_out_personal_columns_for_those_who_may_not_see_them(): void
    {
        Employee::factory()->withPersonalDetails()->create(['oid' => '1234567', 'national_id' => '29001011234567']);

        $read = function (): array {
            $response = EmployeeExport::download(Employee::query(), 'csv');

            return array_values(iterator_to_array(Spreadsheet::read($response->getFile()->getPathname(), 'x.csv')));
        };

        $this->actAs(sensitive: false);
        $plain = $read();
        $this->assertNotContains('National ID', $plain[0]);
        $this->assertNotContains('Emergency Contact 1 Name', $plain[0]);
        $this->assertStringNotContainsString('29001011234567', json_encode($plain));

        $this->actAs(sensitive: true);
        $full = $read();
        $this->assertContains('National ID', $full[0]);
        $this->assertContains('29001011234567', $full[1]);

        Livewire::test(ListEmployees::class)
            ->callAction('export', data: ['format' => 'csv'])
            ->assertFileDownloaded();
    }

    public function test_an_export_imports_back_onto_the_same_people(): void
    {
        $this->actAs(sensitive: true);

        Employee::factory()->withPersonalDetails()->count(3)->create();
        $before = Employee::query()->with('emergencyContacts')->orderBy('id')->get()
            ->map(fn (Employee $employee): array => [$employee->only(['oid', 'name', 'email', 'mobile', 'national_id', 'address', 'job_title']), $employee->emergencyContacts->map->only(['slot', 'name', 'phone'])->all()])
            ->all();

        $response = EmployeeExport::download(Employee::query(), 'xlsx');
        $batch = app(ImportRunner::class)->check(app(EmployeeImporter::class), $response->getFile()->getPathname(), 'export.xlsx', [], auth()->user());

        $this->assertSame(3, $batch->count(ImportRow::UPDATE));

        $this->import($batch);

        $after = Employee::query()->with('emergencyContacts')->orderBy('id')->get()
            ->map(fn (Employee $employee): array => [$employee->only(['oid', 'name', 'email', 'mobile', 'national_id', 'address', 'job_title']), $employee->emergencyContacts->map->only(['slot', 'name', 'phone'])->all()])
            ->all();

        $this->assertSame($before, $after);
        $this->assertSame(3, Employee::query()->count());
    }
}

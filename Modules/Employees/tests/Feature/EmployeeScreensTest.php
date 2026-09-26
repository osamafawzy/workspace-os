<?php

namespace Modules\Employees\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Tests\TestCase;

class EmployeeScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function sensitiveUser(): User
    {
        return User::factory()->withPermissions('employees.view', 'employees.view_sensitive', 'employees.create', 'employees.update', 'audit.view')->create();
    }

    protected function plainUser(): User
    {
        return User::factory()->withPermissions('employees.view', 'employees.create', 'employees.update')->create();
    }

    public function test_the_list_needs_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view')->create())
            ->get('/admin/employees')
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('employees.view')->create())
            ->get('/admin/employees')
            ->assertSuccessful()
            ->assertSee('Employees');
    }

    public function test_the_sidebar_entry_is_the_real_screen_now(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/coming-soon?item=employees')
            ->assertNotFound();
    }

    public function test_an_employee_is_created_with_both_emergency_contacts(): void
    {
        $this->actingAs($this->sensitiveUser());

        $site = Site::factory()->create(['name' => 'Alexandria']);
        $location = Location::query()->create(['site_id' => $site->id, 'name' => 'Floor 2']);
        $department = Department::query()->create(['name' => 'Operations']);

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'oid' => '1234567',
                'name' => 'Ahmed Hassan',
                'status' => EmployeeStatus::Active->value,
                'department_id' => $department->id,
                'site_id' => $site->id,
                'location_id' => $location->id,
                'joined_at' => '2024-03-01',
                'national_id' => '29001011234567',
                'address' => '12 Example Street',
                'firstContact' => ['name' => 'Mona Hassan', 'relationship' => 'Mother', 'phone' => '+20 11 1111 1111'],
                'secondContact' => ['name' => '', 'relationship' => '', 'phone' => ''],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $employee = Employee::query()->where('oid', '1234567')->firstOrFail();

        $this->assertSame('29001011234567', $employee->national_id);
        $this->assertSame($location->id, $employee->location_id);
        $this->assertCount(1, $employee->emergencyContacts);
        $this->assertSame(1, $employee->emergencyContacts->first()->slot);
        $this->assertSame('Mona Hassan', $employee->emergencyContacts->first()->name);
    }

    public function test_the_form_refuses_duplicates_and_a_leaving_date_before_joining(): void
    {
        $this->actingAs($this->sensitiveUser());

        Employee::factory()->create(['oid' => '1111111', 'national_id' => '2900-1011234567']);

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'oid' => '1111111',
                'name' => 'Somebody',
                'status' => EmployeeStatus::Left->value,
                'joined_at' => '2024-03-01',
                'left_at' => '2024-01-01',
                'national_id' => '29001011234567',
            ])
            ->call('create')
            ->assertHasFormErrors(['oid' => 'unique', 'left_at', 'national_id']);
    }

    public function test_personal_data_is_not_sent_to_somebody_who_may_not_see_it(): void
    {
        $employee = Employee::factory()->withPersonalDetails()->create(['national_id' => '29001011234567', 'address' => '12 Secret Street']);
        $contact = $employee->emergencyContacts()->where('slot', 1)->first();

        $this->actingAs($this->plainUser());

        foreach ([
            "/admin/employees/{$employee->id}",
            "/admin/employees/{$employee->id}/edit",
            '/admin/employees',
        ] as $url) {
            $this->get($url)
                ->assertSuccessful()
                ->assertSee($employee->name)
                ->assertDontSee('29001011234567')
                ->assertDontSee('••••')
                ->assertDontSee('Secret Street')
                ->assertDontSee($contact->name)
                ->assertDontSee($contact->phone)
                ->assertDontSee('Emergency contacts');
        }

        // Somebody allowed sees it on the profile, and masked on the list.
        $this->actingAs($this->sensitiveUser());

        $this->get("/admin/employees/{$employee->id}")
            ->assertSee('29001011234567')
            ->assertSee('Secret Street')
            ->assertSee($contact->name)
            ->assertSee('Emergency contacts')
            ->assertSee('History');

        Livewire::test(ListEmployees::class)
            ->assertTableColumnExists('national_id_masked')
            ->toggleAllTableColumns()
            ->assertSee('•••• 4567')
            ->assertDontSee('29001011234567');
    }

    public function test_editing_without_the_permission_leaves_personal_data_alone(): void
    {
        $employee = Employee::factory()->withPersonalDetails()->create(['national_id' => '29001011234567', 'address' => '12 Secret Street']);

        $this->actingAs($this->plainUser());

        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertFormFieldDoesNotExist('national_id')
            ->assertFormFieldDoesNotExist('address')
            ->fillForm(['job_title' => 'Team Leader'])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();

        $this->assertSame('Team Leader', $employee->job_title);
        $this->assertSame('29001011234567', $employee->national_id);
        $this->assertSame('12 Secret Street', $employee->address);
        $this->assertCount(2, $employee->emergencyContacts);
    }

    public function test_clearing_a_contacts_name_removes_the_contact(): void
    {
        $employee = Employee::factory()->withPersonalDetails()->create();

        $this->actingAs($this->sensitiveUser());

        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->fillForm(['secondContact' => ['name' => '', 'relationship' => '', 'phone' => '', 'address' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([1], $employee->emergencyContacts()->pluck('slot')->all());
    }

    public function test_the_profile_shows_only_what_the_user_may_see(): void
    {
        $employee = Employee::factory()->create(['name' => 'Ahmed Hassan', 'job_title' => 'Team Leader']);

        $this->actingAs($this->plainUser());

        // No audit.view, no assets.view: no history, no assets section.
        Livewire::test(ViewEmployee::class, ['record' => $employee->id])
            ->assertSee('Ahmed Hassan')
            ->assertDontSee('Assigned assets')
            ->assertDontSee('Full history');
    }

    public function test_the_list_filters_and_sets_status_in_bulk(): void
    {
        $this->actingAs($this->sensitiveUser());

        $active = Employee::factory()->count(2)->create();
        $left = Employee::factory()->left()->create();

        Livewire::test(ListEmployees::class)
            ->filterTable('status', [EmployeeStatus::Left->value])
            ->assertCanSeeTableRecords([$left])
            ->assertCanNotSeeTableRecords($active)
            ->resetTableFilters()
            ->callTableBulkAction('setStatus', $active, data: ['status' => EmployeeStatus::Left->value]);

        foreach ($active as $employee) {
            $employee->refresh();
            $this->assertSame(EmployeeStatus::Left, $employee->status);
            $this->assertNotNull($employee->left_at);
        }
    }

    public function test_global_search_finds_an_employee_by_oid(): void
    {
        $this->actingAs($this->plainUser());

        Employee::factory()->create(['oid' => '7654321', 'name' => 'Ahmed Hassan']);

        $results = EmployeeResource::getGlobalSearchResults('7654321');

        $this->assertSame(['Ahmed Hassan (7654321)'], $results->map(fn ($result) => (string) $result->title)->values()->all());
    }
}

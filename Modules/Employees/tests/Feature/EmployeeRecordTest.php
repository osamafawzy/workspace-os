<?php

namespace Modules\Employees\Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Role;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\EmergencyContact;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Department;
use Tests\TestCase;

/**
 * What is stored, what is logged, and who gets which permission by default.
 */
class EmployeeRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_national_id_is_encrypted_at_rest_and_hashed_for_comparison(): void
    {
        $employee = Employee::factory()->create(['national_id' => '2900 101-1234567']);

        $raw = DB::table('employees')->where('id', $employee->id)->value('national_id');

        $this->assertNotSame('2900 101-1234567', $raw);
        $this->assertStringNotContainsString('1234567', $raw);
        $this->assertSame('2900 101-1234567', $employee->fresh()->national_id);

        // Spaces and dashes aside, the same ID hashes the same.
        $this->assertSame(Employee::hashNationalId('29001011234567'), $employee->fresh()->national_id_hash);
        $this->assertSame('•••• 4567', $employee->maskedNationalId());

        $employee->update(['national_id' => null]);
        $this->assertNull($employee->fresh()->national_id_hash);
    }

    public function test_personal_data_never_reaches_the_audit_log(): void
    {
        $employee = Employee::factory()->create([
            'name' => 'Ahmed Hassan',
            'national_id' => '29001011234567',
            'address' => '12 Secret Street',
        ]);
        $employee->emergencyContacts()->create(['slot' => 1, 'name' => 'Mona Hassan', 'phone' => '+20 11 5555 0000', 'address' => 'Elsewhere']);
        $employee->update(['national_id' => '29001019999999', 'address' => '99 Other Street', 'job_title' => 'Floor Manager']);

        $logged = AuditLog::query()->get()->map(fn (AuditLog $log): string => json_encode([$log->old_values, $log->new_values]))->implode(' ');

        foreach (['29001011234567', '29001019999999', 'Secret Street', 'Other Street', 'Mona Hassan', '5555', 'Elsewhere'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged, "{$secret} was written to the audit log");
        }

        // That they changed is still recorded.
        $update = AuditLog::query()->where('action', 'updated')->where('auditable_id', $employee->id)->where('auditable_type', $employee->getMorphClass())->firstOrFail();
        $this->assertSame('(hidden)', $update->new_values['national_id']);
        $this->assertSame('(hidden)', $update->new_values['address']);
        $this->assertSame('Floor Manager', $update->new_values['job_title']);
        $this->assertArrayNotHasKey('national_id_hash', $update->new_values);

        $this->assertSame('Employees', AuditLog::query()->where('auditable_type', (new EmergencyContact)->getMorphClass())->value('module'));
    }

    public function test_statuses_are_read_the_way_hr_exports_write_them(): void
    {
        $this->assertSame(EmployeeStatus::Active, EmployeeStatus::fromLoose('ACTIVE'));
        $this->assertSame(EmployeeStatus::OnLeave, EmployeeStatus::fromLoose('Leave of Absence'));
        $this->assertSame(EmployeeStatus::Left, EmployeeStatus::fromLoose('Terminated'));
        $this->assertSame(EmployeeStatus::Left, EmployeeStatus::fromLoose('resigned'));
        $this->assertNull(EmployeeStatus::fromLoose('maybe'));
    }

    public function test_a_department_an_employee_is_in_cannot_be_deleted(): void
    {
        $department = Department::query()->create(['name' => 'Operations']);
        $empty = Department::query()->create(['name' => 'Finance']);

        Employee::factory()->create(['department_id' => $department->id]);

        $this->assertTrue($department->isInUse());
        $this->assertFalse($empty->isInUse());
    }

    public function test_only_it_admin_sees_personal_data_by_default(): void
    {
        $role = fn (string $name): Role => Role::query()->where('name', $name)->firstOrFail();

        $this->assertTrue($role('IT Admin')->grants('employees.view_sensitive'));

        foreach (['IT Engineer', 'IT Technician', 'Viewer'] as $name) {
            $this->assertTrue($role($name)->grants('employees.view'), "{$name} should view employees");
            $this->assertFalse($role($name)->grants('employees.view_sensitive'), "{$name} should not see personal data");
        }

        $this->assertTrue($role('IT Engineer')->grants('employees.import'));
        $this->assertFalse($role('IT Engineer')->grants('employees.delete'));
        $this->assertFalse($role('Viewer')->grants('employees.update'));
    }
}

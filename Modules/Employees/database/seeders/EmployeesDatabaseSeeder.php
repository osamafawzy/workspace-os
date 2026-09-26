<?php

namespace Modules\Employees\Database\Seeders;

use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Import\ImportRunner;
use Illuminate\Database\Seeder;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Imports\EmployeeImporter;
use Modules\Employees\Models\Employee;
use Modules\Settings\Database\Seeders\SettingsDatabaseSeeder;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;

/**
 * Ninety employees, most active, some on leave, some gone; each with emergency
 * contacts; and a small Workday export run through the real import, so the
 * import screens have a finished batch to show.
 *
 * Built from fixed lists rather than Faker: the same people every time, and it
 * runs on a production install, which has no dev dependencies. Idempotent —
 * employees are found by OID.
 */
class EmployeesDatabaseSeeder extends Seeder
{
    public const COUNT = 90;

    public const FIRST_OID = 1000001;

    protected const FIRST_NAMES = [
        'Ahmed', 'Mona', 'Mohamed', 'Sara', 'Omar', 'Nour', 'Youssef', 'Mariam', 'Karim', 'Hana',
        'Mostafa', 'Salma', 'Mahmoud', 'Yasmin', 'Ali', 'Farida', 'Hassan', 'Laila', 'Tarek', 'Dina',
        'Amr', 'Reem', 'Khaled', 'Heba', 'Ibrahim', 'Noha', 'Sherif', 'Aya', 'Hesham', 'Rana',
        'Adel', 'Malak', 'Walid', 'Jana', 'Sameh', 'Habiba', 'Ashraf', 'Rowan', 'Ehab', 'Nada',
    ];

    protected const LAST_NAMES = [
        'Hassan', 'Ibrahim', 'Mahmoud', 'Mostafa', 'Abdelrahman', 'Salem', 'Farouk', 'Nasser', 'Gaber', 'Kamal',
        'Shalaby', 'Fathy', 'Soliman', 'Hamdy', 'Ezzat', 'Ragab', 'Zaki', 'Samir', 'Anwar', 'Lotfy',
        'Badawy', 'Helmy', 'Sabry', 'Mansour', 'Radwan', 'Fawzy', 'Tawfik', 'Wahba', 'Shawky', 'Abbas',
    ];

    protected const STREETS = ['El Horreya Road', 'Fouad Street', 'Abu Qir Street', 'Syria Street', 'El Geish Road', 'Mostafa Kamel Street'];

    protected const DISTRICTS = ['Smouha', 'Sidi Gaber', 'Roushdy', 'Miami', 'Stanley', 'Louran'];

    public const JOB_TITLES = [
        'Operations' => ['Customer Service Advisor', 'Senior Advisor', 'Team Leader', 'Operations Manager'],
        'Quality Assurance' => ['Quality Analyst', 'Quality Team Leader'],
        'Workforce Management' => ['WFM Analyst', 'Real-Time Analyst'],
        'Training' => ['Trainer', 'Training Coordinator'],
        'Information Technology' => ['IT Technician', 'IT Engineer', 'Network Engineer'],
        'Human Resources' => ['HR Generalist', 'Recruiter'],
        'Finance' => ['Accountant', 'Payroll Specialist'],
    ];

    public function run(): void
    {
        $this->call(SettingsDatabaseSeeder::class);

        $departments = Department::query()->whereIn('name', array_keys(self::JOB_TITLES))->get()->keyBy('name');
        $clientAccounts = Account::query()->where('is_active', true)->where('name', '!=', 'Internal IT')->orderBy('name')->get();
        $internal = Account::query()->where('name', 'Internal IT')->first();
        $alexandria = Site::query()->where('name', 'Alexandria Site')->first();
        $cairo = Site::query()->where('name', 'Cairo Site')->first();
        $locations = Location::query()->get()->groupBy('site_id');

        for ($index = 0; $index < self::COUNT; $index++) {
            $oid = (string) (self::FIRST_OID + $index);

            if (Employee::query()->where('oid', $oid)->exists()) {
                continue;
            }

            $first = self::FIRST_NAMES[$index % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[($index * 7) % count(self::LAST_NAMES)];
            $departmentName = array_keys(self::JOB_TITLES)[$this->departmentSlot($index)];
            $titles = self::JOB_TITLES[$departmentName];
            $site = $index % 5 === 4 ? $cairo : $alexandria;
            $siteLocations = $site ? ($locations[$site->getKey()] ?? collect())->reject(fn (Location $location) => in_array($location->name, ['IT Store Room', 'Repair Bench'], true))->values() : collect();
            $joined = now()->subDays(40 + (($index * 53) % 1700))->startOfDay();

            $status = match (true) {
                $index % 15 === 14 => EmployeeStatus::Left,
                $index % 20 === 7 => EmployeeStatus::OnLeave,
                default => EmployeeStatus::Active,
            };

            $employee = Employee::query()->create([
                'oid' => $oid,
                'employee_number' => sprintf('EMP%05d', 10001 + $index),
                'name' => "{$first} {$last}",
                'email' => strtolower("{$first}.{$last}{$index}@example.com"),
                'mobile' => sprintf('+20 1%d %04d %04d', $index % 3, 1000 + ($index * 37) % 9000, 1000 + ($index * 91) % 9000),
                'national_id' => sprintf('2%02d%02d%02d%02d%05d', 85 + ($index % 15), 1 + ($index % 12), 1 + ($index % 28), 1 + ($index % 27), 10000 + $index),
                'address' => sprintf('%d %s, %s, %s', 3 + ($index * 11) % 180, self::STREETS[$index % count(self::STREETS)], self::DISTRICTS[($index * 5) % count(self::DISTRICTS)], $site?->city ?? 'Alexandria'),
                'department_id' => $departments[$departmentName]?->getKey(),
                'job_title' => $titles[$index % count($titles)],
                'account_id' => in_array($departmentName, ['Operations', 'Quality Assurance', 'Workforce Management', 'Training'], true)
                    ? $clientAccounts[$index % max(1, $clientAccounts->count())]?->getKey()
                    : $internal?->getKey(),
                'site_id' => $site?->getKey(),
                'location_id' => $siteLocations->isEmpty() ? null : $siteLocations[$index % $siteLocations->count()]->getKey(),
                'status' => $status,
                'joined_at' => $joined,
                'left_at' => $status === EmployeeStatus::Left ? now()->subDays(3 + ($index % 60))->startOfDay() : null,
                'notes' => $index % 17 === 0 ? 'Works split shifts.' : null,
            ]);

            $employee->emergencyContacts()->create([
                'slot' => 1,
                'name' => self::FIRST_NAMES[($index + 13) % count(self::FIRST_NAMES)]." {$last}",
                'relationship' => ['Mother', 'Father', 'Spouse', 'Brother', 'Sister'][$index % 5],
                'phone' => sprintf('+20 12 %04d %04d', 2000 + ($index * 17) % 7000, 3000 + ($index * 29) % 6000),
                'address' => $employee->address,
            ]);

            // Most have a second contact; some never gave one.
            if ($index % 4 !== 3) {
                $employee->emergencyContacts()->create([
                    'slot' => 2,
                    'name' => self::FIRST_NAMES[($index + 21) % count(self::FIRST_NAMES)].' '.self::LAST_NAMES[($index * 3) % count(self::LAST_NAMES)],
                    'relationship' => ['Friend', 'Cousin', 'Uncle', 'Aunt'][$index % 4],
                    'phone' => sprintf('+20 11 %04d %04d', 4000 + ($index * 13) % 5000, 5000 + ($index * 7) % 4000),
                ]);
            }
        }

        $this->workdayImport();
    }

    /** Operations is most of the building; the other departments share the rest. */
    protected function departmentSlot(int $index): int
    {
        return match (true) {
            $index % 10 < 6 => 0,
            default => 1 + ($index % 6),
        };
    }

    /**
     * Six new starters arriving the way they do in real life: in a Workday
     * export, checked, then imported — so an import batch, its rows and its
     * result are there to look at.
     */
    protected function workdayImport(): void
    {
        if (ImportBatch::query()->where('importer', EmployeeImporter::key())->exists()) {
            return;
        }

        $user = auth()->user() ?? User::query()->orderBy('id')->first();

        if (! $user) {
            return;
        }

        auth()->setUser($user);

        $rows = [
            ['Employee OID', 'Worker', 'Employee ID', 'Work Email', 'Mobile Phone', 'Supervisory Organization', 'Job Profile', 'Program', 'Site', 'Work Location', 'Worker Status', 'Hire Date', 'National ID Number', 'Primary Emergency Contact', 'Emergency Contact Relationship', 'Emergency Contact Phone'],
        ];

        foreach (['Mazen Refaat', 'Lina Adel', 'Seif Nabil', 'Hoda Magdy', 'Fady Emad', 'Rahma Saad'] as $offset => $name) {
            $rows[] = [
                (string) (self::FIRST_OID + self::COUNT + $offset), $name, sprintf('EMP%05d', 10001 + self::COUNT + $offset),
                strtolower(str_replace(' ', '.', $name)).'@example.com', sprintf('+20 10 5555 %04d', 1000 + $offset),
                'Operations', 'Customer Service Advisor', 'Telecom Client A', 'Alexandria Site', 'First Floor', 'Active',
                now()->subDays(7 - $offset)->format('d/m/Y'), sprintf('3010101%07d', 1000 + $offset),
                'Parent of '.explode(' ', $name)[0], 'Mother', sprintf('+20 12 5555 %04d', 2000 + $offset),
            ];
        }

        // One row the check refuses, so the batch shows an invalid row and an
        // error report too.
        $rows[] = ['', 'No OID Given', '', 'not-an-email', '', '', '', '', '', '', 'Maybe', '31/31/2026', '', '', '', ''];

        $path = tempnam(sys_get_temp_dir(), 'workday').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $runner = app(ImportRunner::class);
        $importer = app(EmployeeImporter::class);
        $batch = $runner->check($importer, $path, 'workday-new-starters.csv', [], $user);
        $runner->import($batch, $importer);

        @unlink($path);
    }
}

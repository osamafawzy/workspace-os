<?php

namespace Modules\Employees\Imports;

use App\Models\User;
use App\Support\Import\ImportColumn;
use App\Support\Import\Importer;
use App\Support\Import\ImportRunner;
use App\Support\Import\ParsesDates;
use App\Support\Import\RowCheck;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\EmergencyContact;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Lookup;
use Modules\Settings\Models\Site;

/**
 * Employees from the Workday export.
 *
 * The app is offline, so Workday is never called: somebody exports the worker
 * report to Excel and imports it here. Workday report headings vary with how
 * the report was built, so every column answers to the names it commonly has
 * there ("Hire Date", "Job Profile", "Supervisory Organization"…) as well as
 * this application's own — which are also the export's, so an export can be
 * corrected and imported back.
 *
 * A row is matched to an employee by OID. Departments, accounts, sites and
 * locations are named as the sheet names them and found (or created, with the
 * option on) among the Settings lists.
 *
 * Personal data — the national ID, the address, the emergency contacts — is
 * only imported by somebody allowed to see it. For anyone else those columns
 * are ignored, with a note on the row saying so.
 */
class EmployeeImporter extends Importer
{
    use ParsesDates;

    /** @var array<string, mixed> lookups found once per run, including "not there" */
    protected array $memo = [];

    /** @var array<string, string> national ID hashes seen in this file => row key */
    protected array $nationalIds = [];

    protected bool $sensitive = false;

    public static function key(): string
    {
        return 'employees';
    }

    public static function label(): string
    {
        return 'Employees';
    }

    public function authorize(User $user): bool
    {
        return $user->can('import', Employee::class);
    }

    public function columns(): array
    {
        $contact = fn (int $slot): array => [
            new ImportColumn("ec{$slot}_name", "Emergency Contact {$slot} Name", aliases: ["Emergency Contact {$slot}", "EC{$slot} Name", ...($slot === 1 ? ['Emergency Contact Name', 'Primary Emergency Contact', 'Primary Emergency Contact Name'] : ['Secondary Emergency Contact', 'Secondary Emergency Contact Name'])], example: $slot === 1 ? 'Mona Hassan' : 'Karim Hassan'),
            new ImportColumn("ec{$slot}_relationship", "Emergency Contact {$slot} Relationship", aliases: ["EC{$slot} Relationship", ...($slot === 1 ? ['Emergency Contact Relationship', 'Primary Emergency Contact Relationship'] : ['Secondary Emergency Contact Relationship'])], example: $slot === 1 ? 'Mother' : 'Brother'),
            new ImportColumn("ec{$slot}_phone", "Emergency Contact {$slot} Phone", aliases: ["EC{$slot} Phone", "Emergency Contact {$slot} Mobile", ...($slot === 1 ? ['Emergency Contact Phone', 'Primary Emergency Contact Phone'] : ['Secondary Emergency Contact Phone'])], example: '+20 11 2345 6789'),
            new ImportColumn("ec{$slot}_address", "Emergency Contact {$slot} Address", aliases: ["EC{$slot} Address", ...($slot === 1 ? ['Emergency Contact Address'] : [])], example: ''),
        ];

        return [
            new ImportColumn('oid', 'OID', required: true, aliases: ['Employee OID', 'Oracle ID', 'Worker OID', 'OID Number'], example: '1234567'),
            new ImportColumn('employee_number', 'Employee Number', aliases: ['Employee ID', 'Employee No', 'Emp No', 'Worker ID', 'Staff Number', 'Staff ID'], example: 'EMP10045'),
            new ImportColumn('name', 'Name', required: true, aliases: ['Full Name', 'Employee Name', 'Worker', 'Worker Name', 'Legal Name', 'Preferred Name'], example: 'Ahmed Hassan'),
            new ImportColumn('email', 'Email', aliases: ['Work Email', 'Email Address', 'Business Email', 'Primary Work Email'], example: 'ahmed.hassan@example.com'),
            new ImportColumn('mobile', 'Mobile', aliases: ['Mobile Number', 'Mobile Phone', 'Phone', 'Phone Number', 'Primary Phone', 'Work Phone'], example: '+20 10 1234 5678'),
            new ImportColumn('department', 'Department', aliases: ['Supervisory Organization', 'Dept', 'Department Name', 'Cost Center Name'], example: 'Operations'),
            new ImportColumn('job_title', 'Job Title', aliases: ['Job Profile', 'Business Title', 'Position', 'Position Title', 'Title'], example: 'Customer Service Advisor'),
            new ImportColumn('account', 'Account', aliases: ['Program', 'Programme', 'Client', 'LOB', 'Line of Business', 'Project', 'Account Name'], example: 'Telecom Client A'),
            new ImportColumn('site', 'Site', aliases: ['Site Name', 'Work Site', 'Campus'], example: 'Alexandria'),
            new ImportColumn('location', 'Location', aliases: ['Work Location', 'Location Name', 'Office'], example: 'Floor 2'),
            new ImportColumn('status', 'Status', aliases: ['Employee Status', 'Worker Status', 'Active Status', 'Employment Status'], example: 'Active'),
            new ImportColumn('joined_at', 'Joining Date', aliases: ['Hire Date', 'Original Hire Date', 'Start Date', 'Date of Joining', 'Joined', 'Join Date'], example: '2024-03-01'),
            new ImportColumn('left_at', 'Leaving Date', aliases: ['Termination Date', 'End Date', 'Last Day of Work', 'Date of Leaving', 'Left', 'Leave Date'], example: ''),
            new ImportColumn('national_id', 'National ID', aliases: ['National ID Number', 'National Identifier', 'NID', 'ID Number'], example: '29001011234567'),
            new ImportColumn('address', 'Address', aliases: ['Home Address', 'Primary Home Address', 'Residential Address'], example: '12 Example Street, Alexandria'),
            ...$contact(1),
            ...$contact(2),
            new ImportColumn('notes', 'Notes', aliases: ['Comments', 'Remarks'], example: ''),
        ];
    }

    public function sensitiveFields(): array
    {
        return [
            'national_id', 'address',
            'ec1_name', 'ec1_relationship', 'ec1_phone', 'ec1_address',
            'ec2_name', 'ec2_relationship', 'ec2_phone', 'ec2_address',
        ];
    }

    public function mayRevealSensitive(?User $user): bool
    {
        return $user?->can('viewSensitive', Employee::class) ?? false;
    }

    public function optionFields(): array
    {
        return [
            Toggle::make('create_missing')
                ->label('Create departments, accounts, sites and locations that do not exist yet')
                ->helperText('Off: a row naming one that does not exist is marked invalid instead.')
                ->default(true),
        ];
    }

    public function defaultOptions(): array
    {
        return ['create_missing' => true, 'existing' => ImportRunner::EXISTING_UPDATE];
    }

    public function returnUrl(): ?string
    {
        return EmployeeResource::getUrl('index');
    }

    public function prepare(array $options): void
    {
        $this->memo = [];
        $this->nationalIds = [];
        // Decided by who is importing, at check and again at import.
        $this->sensitive = $this->mayRevealSensitive(auth()->user());
    }

    public function check(array $row, RowCheck $check, array $options): void
    {
        $create = (bool) ($options['create_missing'] ?? true);
        $updating = ($options['existing'] ?? ImportRunner::EXISTING_SKIP) === ImportRunner::EXISTING_UPDATE;

        // ---- who -----------------------------------------------------------
        $oid = $row['oid'];

        if ($oid === '') {
            $check->error('OID is empty.');
        } elseif (mb_strlen($oid) > 50) {
            $check->error('OID is longer than 50 characters.');
        } else {
            $check->key = mb_strtolower($oid);
            $check->existing = $this->remember('employee:'.$check->key, fn () => Employee::query()->where('oid', $oid)->first());
        }

        if ($row['name'] === '') {
            $check->error('Name is empty.');
        }

        if ($row['employee_number'] !== '') {
            $holder = $this->remember('number:'.mb_strtolower($row['employee_number']), fn () => Employee::query()->where('employee_number', $row['employee_number'])->first());

            if ($holder && ! $holder->is($check->existing)) {
                $check->error("Employee number {$row['employee_number']} already belongs to {$holder->name} ({$holder->oid}).");
            }
        }

        // ---- values --------------------------------------------------------
        if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $check->error("\"{$row['email']}\" is not an email address.");
        }

        if ($row['status'] !== '' && ! EmployeeStatus::fromLoose($row['status'])) {
            $check->error("Status \"{$row['status']}\" is not one of: ".collect(EmployeeStatus::cases())->map->getLabel()->implode(', ').'.');
        }

        $joined = $this->dateField($row['joined_at'], 'Joining date', $check);
        $left = $this->dateField($row['left_at'], 'Leaving date', $check);

        if ($joined && $left && $left->lt($joined)) {
            $check->error('The leaving date is before the joining date.');
        }

        // ---- where ---------------------------------------------------------
        foreach (['department' => Department::class, 'account' => Account::class, 'site' => Site::class] as $field => $model) {
            if ($row[$field] !== '' && ! $this->lookup($model, $row[$field])) {
                $label = ucfirst($field);

                $create
                    ? $check->warning("{$label} \"{$row[$field]}\" will be created.")
                    : $check->error("{$label} \"{$row[$field]}\" does not exist. Add it under Settings first.");
            }
        }

        if ($row['location'] !== '') {
            $site = $row['site'] !== '' ? $this->lookup(Site::class, $row['site']) : null;
            $matches = $this->locations($row['location'], $site, $row['site'] !== '');

            if ($matches > 1) {
                $check->error("More than one site has a location called \"{$row['location']}\". Add a Site column to say which.");
            } elseif ($matches === 0) {
                $create && $row['site'] !== ''
                    ? $check->warning("Location \"{$row['location']}\" will be created at {$row['site']}.")
                    : $check->error("Location \"{$row['location']}\" does not exist".($row['site'] !== '' ? " at {$row['site']}" : '').'.');
            }
        }

        // ---- personal data -------------------------------------------------
        $personal = array_filter(array_intersect_key($row, array_flip($this->sensitiveFields())), fn (string $value): bool => $value !== '');

        if ($personal !== [] && ! $this->sensitive) {
            $check->warning('National ID, address and emergency contacts are not imported: you are not allowed to see them.');
        } elseif ($personal !== []) {
            $this->checkPersonal($row, $check);
        }

        $limits = [
            'name' => 150, 'employee_number' => 50, 'email' => 150, 'mobile' => 30, 'job_title' => 150,
            'department' => 100, 'account' => 100, 'site' => 100, 'location' => 100, 'notes' => 5000,
        ];

        foreach ($limits as $field => $limit) {
            if (mb_strlen($row[$field]) > $limit) {
                $check->error("{$field} is longer than {$limit} characters.");
            }
        }

        if ($check->existing && $updating && ! $check->hasErrors()) {
            $check->warning('Updates the existing employee. Empty cells leave its current values alone.');
        }
    }

    public function save(array $row, ?Model $existing, array $options): Model
    {
        $values = [];

        foreach (['employee_number', 'name', 'email', 'mobile', 'job_title', 'notes'] as $field) {
            if ($row[$field] !== '') {
                $values[$field] = $row[$field];
            }
        }

        foreach (['department' => Department::class, 'account' => Account::class, 'site' => Site::class] as $field => $model) {
            if ($row[$field] !== '') {
                $values["{$field}_id"] = $this->lookupOrCreate($model, $row[$field])->getKey();
            }
        }

        if ($row['location'] !== '') {
            $site = $row['site'] !== '' ? $this->lookupOrCreate(Site::class, $row['site']) : null;
            $values['location_id'] = $this->locationOrCreate($row['location'], $site)->getKey();
        }

        if ($row['joined_at'] !== '') {
            $values['joined_at'] = $this->parseDate($row['joined_at']);
        }

        if ($row['left_at'] !== '') {
            $values['left_at'] = $this->parseDate($row['left_at']);
        }

        if ($row['status'] !== '') {
            $values['status'] = EmployeeStatus::fromLoose($row['status']);
        } elseif (isset($values['left_at']) && $values['left_at']->isPast()) {
            // A leaving date in the past with no status says they have left.
            $values['status'] = EmployeeStatus::Left;
        }

        if ($this->sensitive) {
            foreach (Employee::SENSITIVE as $field) {
                if ($row[$field] !== '') {
                    $values[$field] = $row[$field];
                }
            }
        }

        if ($existing instanceof Employee) {
            $existing->update($values);
            $employee = $existing;
        } else {
            $employee = Employee::query()->create(['oid' => $row['oid'], ...$values]);
        }

        if ($this->sensitive) {
            $this->saveContacts($employee, $row);
        }

        unset($this->memo['employee:'.mb_strtolower($row['oid'])]);

        return $employee;
    }

    protected function checkPersonal(array $row, RowCheck $check): void
    {
        if ($row['national_id'] !== '') {
            $hash = Employee::hashNationalId($row['national_id']);
            $holder = Employee::query()->where('national_id_hash', $hash)->first();

            // The ID itself stays out of the message: row notes are shown to
            // whoever looks at the preview.
            if ($holder && ! $holder->is($check->existing)) {
                $check->error("This national ID already belongs to {$holder->name} ({$holder->oid}).");
            }

            if (isset($this->nationalIds[$hash]) && $this->nationalIds[$hash] !== $check->key) {
                $check->error('This national ID is also on another row of this file.');
            }

            $this->nationalIds[$hash] ??= (string) $check->key;

            if (mb_strlen($row['national_id']) > 50) {
                $check->error('National ID is longer than 50 characters.');
            }
        }

        foreach (EmergencyContact::SLOTS as $slot) {
            $filled = array_filter([$row["ec{$slot}_relationship"], $row["ec{$slot}_phone"], $row["ec{$slot}_address"]], fn (string $value): bool => $value !== '');

            if ($filled !== [] && $row["ec{$slot}_name"] === '') {
                $existingContact = $check->existing instanceof Employee
                    ? $check->existing->emergencyContacts()->where('slot', $slot)->exists()
                    : false;

                if (! $existingContact) {
                    $check->error("Emergency contact {$slot} has details but no name.");
                }
            }

            foreach (['name' => 150, 'relationship' => 50, 'phone' => 30] as $field => $limit) {
                if (mb_strlen($row["ec{$slot}_{$field}"]) > $limit) {
                    $check->error("Emergency contact {$slot} {$field} is longer than {$limit} characters.");
                }
            }
        }
    }

    protected function saveContacts(Employee $employee, array $row): void
    {
        foreach (EmergencyContact::SLOTS as $slot) {
            $values = array_filter([
                'name' => $row["ec{$slot}_name"],
                'relationship' => $row["ec{$slot}_relationship"],
                'phone' => $row["ec{$slot}_phone"],
                'address' => $row["ec{$slot}_address"],
            ], fn (string $value): bool => $value !== '');

            if ($values === []) {
                continue;
            }

            $contact = $employee->emergencyContacts()->where('slot', $slot)->first();

            $contact
                ? $contact->update($values)
                : $employee->emergencyContacts()->create(['slot' => $slot, ...$values]);
        }
    }

    /**
     * A row of a Settings list by name or code, case aside.
     *
     * @param  class-string<Lookup>  $model
     */
    protected function lookup(string $model, string $value): ?Lookup
    {
        return $this->remember(class_basename($model).':'.mb_strtolower($value), fn () => $model::query()
            ->where(fn ($query) => $query->whereRaw('lower(name) = ?', [mb_strtolower($value)])->orWhereRaw('lower(code) = ?', [mb_strtolower($value)]))
            ->first());
    }

    /** @param  class-string<Lookup>  $model */
    protected function lookupOrCreate(string $model, string $value): Lookup
    {
        $found = $this->lookup($model, $value);

        if ($found) {
            return $found;
        }

        unset($this->memo[class_basename($model).':'.mb_strtolower($value)]);

        return $model::query()->create(['name' => $value, 'is_active' => true]);
    }

    /** How many locations a name could mean: at the given site, or at any site when none is given. */
    protected function locations(string $name, ?Site $site, bool $siteGiven): int
    {
        if ($siteGiven && ! $site) {
            return 0;
        }

        return (int) $this->remember('location-count:'.($site?->getKey() ?? '*').'|'.mb_strtolower($name), fn () => Location::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($site, fn ($query) => $query->where('site_id', $site->getKey()))
            ->count() ?: null);
    }

    protected function locationOrCreate(string $name, ?Site $site): Location
    {
        $location = Location::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($site, fn ($query) => $query->where('site_id', $site->getKey()))
            ->first();

        unset($this->memo['location-count:'.($site?->getKey() ?? '*').'|'.mb_strtolower($name)]);

        return $location ?? Location::query()->create(['site_id' => $site?->getKey(), 'name' => $name, 'is_active' => true]);
    }

    /**
     * @template T
     *
     * @param  callable(): (T|null)  $find
     * @return T|null
     */
    protected function remember(string $key, callable $find): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $find() ?? false;
        }

        return $this->memo[$key] ?: null;
    }
}

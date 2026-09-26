<?php

namespace Modules\Employees\Providers;

use App\Filament\Pages\ImportData;
use App\Support\Dashboard\QuickActions;
use App\Support\Import\Importers;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Modules\Employees\Imports\EmployeeImporter;
use Modules\Employees\Models\Employee;
use Modules\Employees\Policies\EmployeePolicy;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Nwidart\Modules\Support\ModuleServiceProvider;

class EmployeesServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Employees';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'employees';

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Permissions::class)->register('Employees', [
            'employees.view' => 'View employees',
            'employees.view_sensitive' => 'See national IDs, home addresses and emergency contacts',
            'employees.create' => 'Add employees',
            'employees.update' => 'Edit employees',
            'employees.delete' => 'Delete employees',
            'employees.import' => 'Import employees from the Workday export',
            'employees.export' => 'Export employees to a spreadsheet',
        ]);

        Gate::policy(Employee::class, EmployeePolicy::class);

        $this->app->make(QuickActions::class)->add(
            'import-employees', 'Import from Workday', 'Bring the latest worker report in.', 'heroicon-o-arrow-up-tray',
            fn (): string => ImportData::getUrl(['importer' => EmployeeImporter::key()]),
            fn (): bool => auth()->user()?->can('import', Employee::class) ?? false,
            80,
        );

        $this->app->make(Importers::class)->register(EmployeeImporter::class);

        // Settings cannot know employees exist, so this module tells it: a
        // department, account, site or location an employee is in stays.
        Department::inUseWhen(fn (Department $department): bool => Employee::query()->where('department_id', $department->getKey())->exists());
        Account::inUseWhen(fn (Account $account): bool => Employee::query()->where('account_id', $account->getKey())->exists());
        Site::inUseWhen(fn (Site $site): bool => Employee::query()->where('site_id', $site->getKey())->exists());
        Location::inUseWhen(fn (Location $location): bool => Employee::query()->where('location_id', $location->getKey())->exists());
    }
}

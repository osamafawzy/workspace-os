<?php

namespace Modules\Settings\Providers;

use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Modules\Settings\Policies\LookupPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SettingsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Settings';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'settings';

    public function boot(): void
    {
        parent::boot();

        $permissions = $this->app->make(Permissions::class);

        $permissions->register('Settings', [
            'settings.manage' => 'Change company branding and the sidebar',
        ]);

        $permissions->register('Lookups', [
            'lookups.view' => 'View sites, locations, accounts and departments',
            'lookups.create' => 'Add sites, locations, accounts and departments',
            'lookups.update' => 'Edit sites, locations, accounts and departments',
            'lookups.delete' => 'Delete sites, locations, accounts and departments',
        ]);

        foreach ([Site::class, Location::class, Account::class, Department::class] as $model) {
            Gate::policy($model, LookupPolicy::class);
        }
    }
}

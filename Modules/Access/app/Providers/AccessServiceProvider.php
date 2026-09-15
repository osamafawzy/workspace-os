<?php

namespace Modules\Access\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Console\GrantSuperAdmin;
use Modules\Access\Models\Role;
use Modules\Access\Policies\RolePolicy;
use Modules\Access\Policies\UserPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AccessServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Access';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'access';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        GrantSuperAdmin::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $permissions = $this->app->make(Permissions::class);

        $permissions->register('Users', [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.update' => 'Edit users and the roles they hold',
            'users.delete' => 'Delete users',
        ]);

        $permissions->register('Roles', [
            'roles.view' => 'View roles',
            'roles.create' => 'Create roles',
            'roles.update' => 'Edit roles and their permissions',
            'roles.delete' => 'Delete roles',
        ]);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}

<?php

namespace Modules\Workspace\Providers;

use App\Support\Permissions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Policies\FloorPolicy;
use Modules\Workspace\Policies\WorkstationPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class WorkspaceServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Workspace';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'workspace';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $permissions = $this->app->make(Permissions::class);

        $permissions->register('Floors', [
            'floors.view' => 'View floors and their plans',
            'floors.create' => 'Create floors',
            'floors.update' => 'Edit floors, including the plan drawing',
            'floors.delete' => 'Delete floors',
            'floors.arrange' => 'Arrange desks on the plan',
        ]);

        $permissions->register('Workstations', [
            'workstations.view' => 'View workstations',
            'workstations.create' => 'Create workstations',
            'workstations.update' => 'Edit workstations and their details',
            'workstations.delete' => 'Delete workstations',
        ]);

        Gate::policy(Floor::class, FloorPolicy::class);
        Gate::policy(Workstation::class, WorkstationPolicy::class);
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}

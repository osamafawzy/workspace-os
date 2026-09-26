<?php

namespace Modules\Workspace\Providers;

use App\Support\Dashboard\QuickActions;
use App\Support\Import\Importers;
use App\Support\Permissions;
use App\Support\Reports\Reports;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Gate;
use Modules\Settings\Models\Site;
use Modules\Workspace\Filament\Admin\Pages\FloorMapping;
use Modules\Workspace\Filament\Admin\Pages\SearchWorkstation;
use Modules\Workspace\FloorMap\FloorObjectTypes;
use Modules\Workspace\Imports\WorkstationImporter;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Policies\AreaPolicy;
use Modules\Workspace\Policies\BuildingPolicy;
use Modules\Workspace\Policies\FloorPolicy;
use Modules\Workspace\Policies\NetworkPolicy;
use Modules\Workspace\Policies\WorkstationPolicy;
use Modules\Workspace\Reports\FloorReport;
use Modules\Workspace\Reports\RackReport;
use Modules\Workspace\Reports\SwitchPortReport;
use Modules\Workspace\Reports\WorkstationReport;
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
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // The kinds of object a floor map holds. Other modules add theirs.
        $this->app->singleton(FloorObjectTypes::class, fn (): FloorObjectTypes => FloorObjectTypes::withDefaults());
    }

    public function boot(): void
    {
        parent::boot();

        // The map editor is a plain JavaScript file and stylesheet served from
        // this server (`php artisan filament:assets` copies them to public/),
        // loaded only on the map page. Versioned by their contents, so a
        // changed editor is never served stale from a browser cache.
        $js = module_path($this->name, 'resources/js/floor-map.js');
        $css = module_path($this->name, 'resources/css/floor-map.css');

        FilamentAsset::register([
            AlpineComponent::make('floor-map', $js),
            Css::make('floor-map', $css)->loadedOnRequest(),
        ], 'app');

        if (is_file($js) && is_file($css)) {
            FilamentAsset::appVersion(substr(md5(md5_file($js).md5_file($css)), 0, 12));
        }

        $permissions = $this->app->make(Permissions::class);

        $permissions->register('Floors', [
            'floors.view' => 'View buildings, floors, areas and plans',
            'floors.create' => 'Create buildings and floors',
            'floors.update' => 'Edit buildings, floors and their areas, including the plan drawing',
            'floors.delete' => 'Delete buildings and floors',
            'floors.arrange' => 'Arrange desks on the plan',
        ]);

        $permissions->register('Workstations', [
            'workstations.view' => 'View workstations',
            'workstations.create' => 'Create workstations',
            'workstations.update' => 'Edit workstations and their details',
            'workstations.delete' => 'Delete workstations',
            'workstations.import' => 'Import workstations from a spreadsheet',
            'workstations.export' => 'Export workstations to a spreadsheet',
        ]);

        $permissions->register('Network', [
            'network.view' => 'View racks, switches, ports and VLANs',
            'network.create' => 'Add racks, switches, ports and VLANs',
            'network.update' => 'Edit racks, switches, ports and VLANs',
            'network.delete' => 'Delete racks, switches, ports and VLANs',
        ]);

        Gate::policy(Building::class, BuildingPolicy::class);
        Gate::policy(Floor::class, FloorPolicy::class);
        Gate::policy(Area::class, AreaPolicy::class);
        Gate::policy(Workstation::class, WorkstationPolicy::class);

        foreach ([Rack::class, NetworkSwitch::class, SwitchPort::class, Vlan::class] as $model) {
            Gate::policy($model, NetworkPolicy::class);
        }

        $this->app->make(Importers::class)->register(WorkstationImporter::class);

        $reports = $this->app->make(Reports::class);

        foreach ([WorkstationReport::class, FloorReport::class, SwitchPortReport::class, RackReport::class] as $report) {
            $reports->register($report);
        }

        // The dashboard's shortcuts to this module.
        $quick = $this->app->make(QuickActions::class);
        $quick->add('search-workstation', 'Find a workstation', 'By ID, PC, port, IP or MAC, and see it on the map.', 'heroicon-o-magnifying-glass', fn (): string => SearchWorkstation::getUrl(), fn (): bool => SearchWorkstation::canAccess(), 10);
        $quick->add('floor-mapping', 'Floor maps', 'Open a floor and arrange its desks.', 'heroicon-o-map', fn (): string => FloorMapping::getUrl(), fn (): bool => FloorMapping::canAccess(), 20);

        // Settings cannot know buildings and VLANs exist, so this module tells
        // it: a site with either cannot be deleted.
        Site::inUseWhen(fn (Site $site): bool => Building::query()->where('site_id', $site->getKey())->exists()
            || Vlan::query()->where('site_id', $site->getKey())->exists());
    }
}

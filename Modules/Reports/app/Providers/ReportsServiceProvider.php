<?php

namespace Modules\Reports\Providers;

use App\Support\Dashboard\QuickActions;
use App\Support\Permissions;
use Modules\Reports\Filament\Admin\Pages\ReportsIndex;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Reports screens. The reports themselves belong to the modules whose
 * records they list, and are registered from those modules' providers.
 */
class ReportsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Reports';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'reports';

    public function boot(): void
    {
        parent::boot();

        $this->app->make(Permissions::class)->register('Reports', [
            'reports.view' => 'Open reports (each also needs permission to see what it lists)',
            'reports.export' => 'Export reports to Excel and CSV',
            'reports.print' => 'Print reports and save them as PDF',
        ]);

        $this->app->make(QuickActions::class)->add(
            'reports', 'Reports', 'Inventory, assignments, returns, network and audit — export or print.', 'heroicon-o-chart-bar',
            fn (): string => ReportsIndex::getUrl(), fn (): bool => ReportsIndex::canAccess(), 90,
        );
    }
}

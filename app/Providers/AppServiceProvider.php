<?php

namespace App\Providers;

use App\Support\Audit\AuditLogger;
use App\Support\Branding;
use App\Support\Dashboard\QuickActions;
use App\Support\Import\Importers;
use App\Support\Navigation\Navigation;
use App\Support\Permissions;
use App\Support\Reports\Reports;
use App\Support\Settings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One catalogue for the whole request, filled in by each module's
        // provider as it boots.
        $this->app->singleton(Permissions::class);
        $this->app->singleton(Importers::class);
        $this->app->singleton(Reports::class);
        $this->app->singleton(QuickActions::class);

        // Read on every page (the sidebar and the brand), so loaded once.
        $this->app->singleton(Settings::class);
        $this->app->singleton(Branding::class);
        $this->app->singleton(Navigation::class);
        $this->app->singleton(AuditLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

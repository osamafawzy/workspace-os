<?php

namespace App\Providers;

use App\Support\Permissions;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

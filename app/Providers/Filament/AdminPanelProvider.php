<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ComingSoon;
use App\Filament\Pages\Dashboard;
use App\Support\Branding;
use App\Support\ModuleComponents;
use App\Support\Navigation\Navigation;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Name, logo and colour come from Settings → Company, and are
            // resolved per request so a change shows on the next page load.
            ->colors(fn (): array => app(Branding::class)->panelColors())
            ->brandName(fn (): string => app(Branding::class)->name())
            ->brandLogo(fn (): ?string => app(Branding::class)->logoUrl())
            ->darkModeBrandLogo(fn (): ?string => app(Branding::class)->darkLogoUrl())
            ->brandLogoHeight('2.25rem')
            // The floor plan is the screen this product is heading towards,
            // and a plan drawing squeezed into a centred column is a plan
            // drawing nobody can read.
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups($this->navigationGroups())
            ->navigationItems(ComingSoon::plannedNavigationItems())
            ->pages([
                Dashboard::class,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                ValidateCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // Each module hands the panel its own screens, so adding a module
        // never means editing this file.
        return ModuleComponents::discover($panel, 'Admin');
    }

    /**
     * The sidebar groups, keyed by their registry key so items can name
     * their group by key and survive it being renamed.
     *
     * @return array<string, NavigationGroup>
     */
    protected function navigationGroups(): array
    {
        $groups = [];

        foreach (app(Navigation::class)->groups() as $key => $group) {
            $groups[$key] = NavigationGroup::make(fn (): string => app(Navigation::class)->groups()[$key]['label'] ?? $group['label'])
                ->icon($group['icon'])
                ->collapsed(fn (): bool => app(Navigation::class)->groups()[$key]['collapsed'] ?? false);
        }

        return $groups;
    }
}

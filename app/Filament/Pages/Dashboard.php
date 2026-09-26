<?php

namespace App\Filament\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The panel home.
 *
 * Its widgets live in the modules that own the numbers — Assets, Workspace,
 * Employees, Audit — and are discovered with them; each one decides whether
 * the signed-in user may see it. Their `$sort` lays the page out:
 *
 *   -100  quick actions (core, filled in by the modules)
 *     10  assets · 20 workstations · 30 employees      (summary cards)
 *     40  assets by status · 41 assignments and returns (charts, side by side)
 *     50  workstation status per floor                  (chart, full width)
 *     60  recently assigned · 61 recently returned      (side by side)
 *     70  recent activity                               (full width)
 */
class Dashboard extends BaseDashboard
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'dashboard';

    public function getColumns(): int|array
    {
        return ['md' => 2];
    }

    public function getSubheading(): ?string
    {
        $name = auth()->user()?->name;

        return $name ? 'Signed in as '.$name.' · '.now()->format('l j F Y') : null;
    }
}

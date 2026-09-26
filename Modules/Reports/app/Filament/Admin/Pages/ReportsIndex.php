<?php

namespace Modules\Reports\Filament\Admin\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use App\Support\Reports\Report;
use App\Support\Reports\Reports;
use Filament\Pages\Page;

/**
 * Reports → All Reports: every report this user may open, under its heading.
 */
class ReportsIndex extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'reports';

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Reports';

    protected string $view = 'reports::filament.pages.reports-index';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('reports.view') ?? false;
    }

    /** @return array<string, list<Report>> */
    public function groups(): array
    {
        return app(Reports::class)->visibleTo(auth()->user());
    }

    public function reportUrl(Report $report): string
    {
        return ViewReport::getUrl(['report' => $report::key()]);
    }
}

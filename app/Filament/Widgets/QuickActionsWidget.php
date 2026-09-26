<?php

namespace App\Filament\Widgets;

use App\Support\Dashboard\QuickActions;
use Filament\Widgets\Widget;

/** The top of the dashboard: the day's common jobs, one click each. */
class QuickActionsWidget extends Widget
{
    protected static ?int $sort = -100;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-actions';

    public static function canView(): bool
    {
        return app(QuickActions::class)->visible() !== [];
    }

    /** @return list<array{key: string, label: string, description: string, icon: string, url: string}> */
    public function actions(): array
    {
        return app(QuickActions::class)->visible();
    }
}

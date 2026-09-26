<?php

namespace Modules\Assets\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Modules\Assets\Models\AssetAssignment;
use Modules\Assets\Models\AssetReturn;

/** Assets going out against assets coming back, week by week or month by month. */
class AssignmentsReturnsChart extends ChartWidget
{
    protected static ?int $sort = 41;

    protected ?string $heading = 'Assignments and returns';

    protected ?string $maxHeight = '280px';

    public ?string $filter = 'weeks';

    public static function canView(): bool
    {
        return (auth()->user()?->hasPermission('assets.view') ?? false)
            && (auth()->user()?->hasPermission('assignments.view') ?? false);
    }

    protected function getFilters(): ?array
    {
        return [
            'weeks' => 'Last 12 weeks',
            'months' => 'Last 12 months',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $months = $this->filter === 'months';
        $periods = collect(range(11, 0))->map(fn (int $ago): Carbon => $months
            ? now()->startOfMonth()->subMonths($ago)
            : now()->startOfWeek()->subWeeks($ago));
        $from = $periods->first();

        // Bucketed in PHP: date functions differ between MySQL and SQLite, and
        // a year of assignments is a few thousand rows at most.
        $bucket = fn (Carbon $at): string => $months ? $at->format('Y-m') : $at->copy()->startOfWeek()->format('Y-m-d');
        $keys = $periods->map(fn (Carbon $period): string => $bucket($period));

        $assigned = AssetAssignment::query()->where('assigned_at', '>=', $from)->pluck('assigned_at')
            ->countBy(fn (Carbon $at): string => $bucket($at));
        $returned = AssetReturn::query()->where('returned_at', '>=', $from)->pluck('returned_at')
            ->countBy(fn (Carbon $at): string => $bucket($at));

        return [
            'labels' => $periods->map(fn (Carbon $period): string => $months ? $period->format('M Y') : $period->format('d M'))->all(),
            'datasets' => [
                [
                    'label' => 'Assigned',
                    'data' => $keys->map(fn (string $key): int => (int) ($assigned[$key] ?? 0))->all(),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Returned',
                    'data' => $keys->map(fn (string $key): int => (int) ($returned[$key] ?? 0))->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}

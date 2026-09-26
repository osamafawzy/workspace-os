<?php

namespace Modules\Workspace\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

/** Each floor's desks, stacked by status, in the map's status colours. */
class WorkstationStatusByFloorChart extends ChartWidget
{
    protected static ?int $sort = 50;

    protected ?string $heading = 'Workstation status per floor';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public static function canView(): bool
    {
        return (auth()->user()?->can('viewAny', Workstation::class) ?? false)
            && (auth()->user()?->can('viewAny', Floor::class) ?? false);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $floors = Floor::query()->with('building')->inBuildingOrder()->get();
        $counts = Workstation::query()
            ->selectRaw('floor_id, status, count(*) as total')
            ->groupBy('floor_id', 'status')
            ->get()
            ->groupBy('floor_id');

        return [
            'labels' => $floors->map(fn (Floor $floor): string => $floor->fullName())->all(),
            'datasets' => collect(WorkstationStatus::cases())
                ->map(fn (WorkstationStatus $status): array => [
                    'label' => $status->getLabel(),
                    'data' => $floors->map(fn (Floor $floor): int => (int) ($counts->get($floor->getKey())?->firstWhere('status', $status)?->total ?? 0))->all(),
                    'backgroundColor' => $status->mapColor(),
                    'borderWidth' => 0,
                ])
                // A status nobody has only adds a legend entry.
                ->filter(fn (array $dataset): bool => array_sum($dataset['data']) > 0)
                ->values()
                ->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}

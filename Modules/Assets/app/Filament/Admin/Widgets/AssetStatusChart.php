<?php

namespace Modules\Assets\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;

/** The register by status. */
class AssetStatusChart extends ChartWidget
{
    protected static ?int $sort = 40;

    protected ?string $heading = 'Assets by status';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Asset::class) ?? false;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $byStatus = Asset::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $statuses = collect(AssetStatus::cases())->filter(fn (AssetStatus $status): bool => (int) ($byStatus[$status->value] ?? 0) > 0)->values();

        return [
            'labels' => $statuses->map(fn (AssetStatus $status): string => $status->getLabel())->all(),
            'datasets' => [[
                'label' => 'Assets',
                'data' => $statuses->map(fn (AssetStatus $status): int => (int) $byStatus[$status->value])->all(),
                'backgroundColor' => $statuses->map(fn (AssetStatus $status): string => $status->chartColor())->all(),
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}

<?php

namespace Modules\Assets\Filament\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\Asset;
use Modules\Employees\Enums\EmployeeStatus;

/** Assets at a glance: how many, with whom, ready to go, and what needs chasing. */
class AssetStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Assets';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Asset::class) ?? false;
    }

    protected function getStats(): array
    {
        $byStatus = Asset::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (AssetStatus ...$statuses): int => collect($statuses)->sum(fn (AssetStatus $status): int => (int) ($byStatus[$status->value] ?? 0));
        $list = fn (array $filters): string => AssetResource::getUrl('index', ['filters' => $filters]);
        $statuses = fn (AssetStatus ...$statuses): array => ['status' => ['values' => array_map(fn (AssetStatus $status): string => $status->value, $statuses)]];

        $heldByLeavers = Asset::query()->whereHas('employee', fn (Builder $employee) => $employee->where('status', EmployeeStatus::Left))->count();
        $expiring = Asset::query()->warrantyExpiring()->count();
        $headsets = Asset::query()->headsets()->count();

        return [
            Stat::make('Assets', number_format((int) $byStatus->sum()))
                ->description(number_format($headsets).' headset(s)')
                ->icon('heroicon-o-cube')
                ->url(AssetResource::getUrl('index')),

            Stat::make('Assigned', number_format($count(AssetStatus::Assigned)))
                ->description('With employees')
                ->color('info')
                ->url($list($statuses(AssetStatus::Assigned))),

            Stat::make('Ready to hand out', number_format($count(AssetStatus::Available, AssetStatus::Returned)))
                ->description(number_format($count(AssetStatus::Returned)).' returned, to check')
                ->color('success')
                ->url($list($statuses(AssetStatus::Available, AssetStatus::Returned))),

            Stat::make('In repair or lost', number_format($count(AssetStatus::InRepair, AssetStatus::Lost)))
                ->color($count(AssetStatus::InRepair, AssetStatus::Lost) > 0 ? 'warning' : 'gray')
                ->url($list($statuses(AssetStatus::InRepair, AssetStatus::Lost))),

            Stat::make('Held by leavers', number_format($heldByLeavers))
                ->description('Employees who have left')
                ->color($heldByLeavers > 0 ? 'danger' : 'success'),

            Stat::make('Warranty ending', number_format($expiring))
                ->description('Within '.Asset::WARRANTY_WARNING_DAYS.' days')
                ->color($expiring > 0 ? 'warning' : 'gray')
                ->url($list(['warranty' => ['value' => 'expiring']])),
        ];
    }
}

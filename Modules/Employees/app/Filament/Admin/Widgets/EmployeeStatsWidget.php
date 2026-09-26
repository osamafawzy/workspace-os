<?php

namespace Modules\Employees\Filament\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\Employee;

/** Employees at a glance. */
class EmployeeStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 30;

    protected ?string $heading = 'Employees';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Employee::class) ?? false;
    }

    protected function getStats(): array
    {
        $byStatus = Employee::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $list = fn (EmployeeStatus $status): string => EmployeeResource::getUrl('index', ['filters' => ['status' => ['values' => [$status->value]]]]);

        return [
            Stat::make('Active', number_format((int) ($byStatus[EmployeeStatus::Active->value] ?? 0)))
                ->icon('heroicon-o-users')
                ->color('success')
                ->url($list(EmployeeStatus::Active)),

            Stat::make('On leave', number_format((int) ($byStatus[EmployeeStatus::OnLeave->value] ?? 0)))
                ->color('warning')
                ->url($list(EmployeeStatus::OnLeave)),

            Stat::make('Joined in the last 30 days', number_format(Employee::query()->where('joined_at', '>=', today()->subDays(30))->count()))
                ->color('info'),

            Stat::make('Left in the last 30 days', number_format(Employee::query()->where('status', EmployeeStatus::Left)->where('left_at', '>=', today()->subDays(30))->count()))
                ->color('gray')
                ->url($list(EmployeeStatus::Left)),
        ];
    }
}

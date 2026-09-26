<?php

namespace Modules\Workspace\Filament\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

/** Workstations at a glance: how many, which need attention, how much is mapped. */
class WorkstationStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Workstations';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Workstation::class) ?? false;
    }

    protected function getStats(): array
    {
        $byStatus = Workstation::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $byStatus->sum();
        $attention = [WorkstationStatus::Faulty, WorkstationStatus::Offline, WorkstationStatus::UnderMaintenance];
        $needsAttention = collect($attention)->sum(fn (WorkstationStatus $status): int => (int) ($byStatus[$status->value] ?? 0));
        $placed = Workstation::query()->placed()->count();
        $list = fn (array $statuses): string => WorkstationResource::getUrl('index', ['filters' => ['status' => ['values' => array_map(fn (WorkstationStatus $status): string => $status->value, $statuses)]]]);

        return [
            Stat::make('Workstations', number_format($total))
                ->description(Floor::query()->count().' floor(s)')
                ->icon('heroicon-o-computer-desktop')
                ->url(WorkstationResource::getUrl('index')),

            Stat::make('Active', number_format((int) ($byStatus[WorkstationStatus::Active->value] ?? 0)))
                ->description(number_format((int) ($byStatus[WorkstationStatus::Available->value] ?? 0)).' available')
                ->color('success')
                ->url($list([WorkstationStatus::Active])),

            Stat::make('Need attention', number_format($needsAttention))
                ->description('Faulty, offline or under maintenance')
                ->color($needsAttention > 0 ? 'danger' : 'success')
                ->url($list($attention)),

            Stat::make('On the map', $total > 0 ? round($placed / $total * 100).'%' : '—')
                ->description(number_format($total - $placed).' not placed yet')
                ->color($total > 0 && $placed === $total ? 'success' : 'warning')
                ->url(WorkstationResource::getUrl('index', ['filters' => ['placed' => ['value' => '0']]])),
        ];
    }
}

<?php

namespace Modules\Workspace\Reports;

use App\Models\User;
use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Floor;

/** One row per floor: its size and how its desks stand. */
class FloorReport extends Report
{
    public static function key(): string
    {
        return 'floors';
    }

    public static function label(): string
    {
        return 'Floors';
    }

    public static function description(): string
    {
        return 'Each floor with its size, how many workstations it has, how many are on the map, and how many are in each status.';
    }

    public static function group(): string
    {
        return 'Floors and network';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Floor::class);
    }

    public function query(): Builder
    {
        $counts = [
            'workstations',
            'workstations as placed_count' => fn (Builder $query) => $query->placed(),
        ];

        foreach (WorkstationStatus::cases() as $status) {
            $counts["workstations as status_{$status->value}_count"] = fn (Builder $query) => $query->where('status', $status);
        }

        return Floor::query()->with('building.site')->withCount($counts);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        $like = static::like($term);

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->orWhereHas('building', fn (Builder $building) => $building->whereRaw("lower(name) like ? escape '!'", [$like])));
    }

    public function defaultSort(): ?string
    {
        return 'level:asc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('building', 'Building', fn (Floor $floor) => $floor->building?->name),
            ReportColumn::make('name', 'Floor', fn (Floor $floor) => $floor->name)->sortable('name'),
            ReportColumn::make('level', 'Level', fn (Floor $floor) => (string) $floor->level)->sortable('level'),
            ReportColumn::make('size', 'Size (m)', fn (Floor $floor) => rtrim(rtrim(number_format($floor->width_m, 1), '0'), '.').' × '.rtrim(rtrim(number_format($floor->depth_m, 1), '0'), '.'))->hiddenByDefault(),
            ReportColumn::make('workstations', 'Workstations', fn (Floor $floor) => (string) $floor->workstations_count),
            ReportColumn::make('placed', 'On Map', fn (Floor $floor) => (string) $floor->placed_count),
            ...array_map(
                fn (WorkstationStatus $status): ReportColumn => ReportColumn::make("status_{$status->value}", $status->getLabel(), fn (Floor $floor) => (string) $floor->getAttribute("status_{$status->value}_count")),
                WorkstationStatus::cases(),
            ),
            ReportColumn::make('drawing', 'Drawing', fn (Floor $floor) => $floor->hasPlan())->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('building_id')->label('Building')->relationship('building', 'name')->preload(),
        ];
    }
}

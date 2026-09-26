<?php

namespace Modules\Workspace\Reports;

use App\Models\User;
use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;

/** One row per rack: its switches and how full their ports are. */
class RackReport extends Report
{
    public static function key(): string
    {
        return 'racks';
    }

    public static function label(): string
    {
        return 'Racks';
    }

    public static function description(): string
    {
        return 'Each rack with its switches, their recorded ports, how many are patched and how many are free.';
    }

    public static function group(): string
    {
        return 'Floors and network';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Rack::class);
    }

    public function query(): Builder
    {
        return Rack::query()
            ->select('racks.*')
            ->with(['building', 'floor', 'switches'])
            ->withCount('switches')
            ->addSelect([
                'ports_count' => SwitchPort::query()
                    ->selectRaw('count(*)')
                    ->whereIn('network_switch_id', NetworkSwitch::query()->select('id')->whereColumn('rack_id', 'racks.id')),
                'ports_in_use_count' => SwitchPort::query()
                    ->selectRaw('count(*)')
                    ->whereIn('network_switch_id', NetworkSwitch::query()->select('id')->whereColumn('rack_id', 'racks.id'))
                    ->whereHas('workstation'),
            ]);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        $like = static::like($term);

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(racks.number) like ? escape '!'", [$like])
            ->orWhereRaw("lower(racks.name) like ? escape '!'", [$like]));
    }

    public function defaultSort(): ?string
    {
        return 'number:asc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('building', 'Building', fn (Rack $rack) => $rack->building?->name),
            ReportColumn::make('floor', 'Floor', fn (Rack $rack) => $rack->floor?->name),
            ReportColumn::make('rack', 'Rack', fn (Rack $rack) => $rack->label())->sortable('number'),
            ReportColumn::make('switches_count', 'Switches', fn (Rack $rack) => (string) $rack->switches_count),
            ReportColumn::make('switches', 'Switch Numbers', fn (Rack $rack) => $rack->switches->sortBy('number')->pluck('number')->implode(', ')),
            ReportColumn::make('ports', 'Ports Recorded', fn (Rack $rack) => (string) (int) $rack->ports_count),
            ReportColumn::make('in_use', 'Patched', fn (Rack $rack) => (string) (int) $rack->ports_in_use_count),
            ReportColumn::make('free', 'Free', fn (Rack $rack) => (string) ((int) $rack->ports_count - (int) $rack->ports_in_use_count)),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('building_id')->label('Building')->relationship('building', 'name')->preload(),
        ];
    }
}

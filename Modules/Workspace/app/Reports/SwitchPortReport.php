<?php

namespace Modules\Workspace\Reports;

use App\Models\User;
use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;

/** Every switch port and what is patched into it. */
class SwitchPortReport extends Report
{
    public static function key(): string
    {
        return 'switch-ports';
    }

    public static function label(): string
    {
        return 'Switch Ports';
    }

    public static function description(): string
    {
        return 'Every recorded switch port, the workstation patched into it, and the free ones.';
    }

    public static function group(): string
    {
        return 'Floors and network';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', NetworkSwitch::class);
    }

    public function query(): Builder
    {
        return SwitchPort::query()->with(['networkSwitch.building', 'networkSwitch.rack', 'workstation.floor', 'workstation.vlan']);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        $like = static::like($term);

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->orWhereHas('networkSwitch', fn (Builder $switch) => $switch->whereRaw("lower(number) like ? escape '!'", [$like])->orWhereRaw("lower(name) like ? escape '!'", [$like]))
            ->orWhereHas('workstation', fn (Builder $desk) => $desk->whereRaw("lower(name) like ? escape '!'", [$like])->orWhereRaw("lower(computer_name) like ? escape '!'", [$like])));
    }

    public function searchPlaceholder(): ?string
    {
        return 'Switch, port, workstation, PC…';
    }

    public function defaultSort(): ?string
    {
        return 'network_switch_id:asc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('building', 'Building', fn (SwitchPort $port) => $port->networkSwitch?->building?->name)->hiddenByDefault(),
            ReportColumn::make('rack', 'Rack', fn (SwitchPort $port) => $port->networkSwitch?->rack?->number),
            ReportColumn::make('switch', 'Switch', fn (SwitchPort $port) => $port->networkSwitch?->label()),
            ReportColumn::make('port', 'Port', fn (SwitchPort $port) => $port->name)->mono()->sortable('name'),
            ReportColumn::make('number', 'Port Number', fn (SwitchPort $port) => $port->number)->hiddenByDefault(),
            ReportColumn::make('workstation', 'Workstation', fn (SwitchPort $port) => $port->workstation?->displayLabel()),
            ReportColumn::make('split', 'Split', fn (SwitchPort $port) => $port->workstation?->port_split_number),
            ReportColumn::make('vlan', 'VLAN', fn (SwitchPort $port) => $port->workstation?->vlan?->number),
            ReportColumn::make('pc', 'PC Name', fn (SwitchPort $port) => $port->workstation?->computer_name),
            ReportColumn::make('in_use', 'In Use', fn (SwitchPort $port) => $port->workstation !== null),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('network_switch_id')
                ->label('Switch')
                ->options(fn (): array => NetworkSwitch::query()->orderBy('number')->get()->mapWithKeys(fn (NetworkSwitch $switch): array => [$switch->getKey() => $switch->label()])->all())
                ->searchable(),
            SelectFilter::make('building')
                ->label('Building')
                ->options(fn (): array => Building::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('networkSwitch', fn (Builder $switch) => $switch->where('building_id', $data['value']))
                    : $query),
            TernaryFilter::make('in_use')
                ->label('In use')
                ->queries(
                    true: fn (Builder $query): Builder => $query->has('workstation'),
                    false: fn (Builder $query): Builder => $query->doesntHave('workstation'),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ];
    }
}

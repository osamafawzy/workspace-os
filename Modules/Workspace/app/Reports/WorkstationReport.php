<?php

namespace Modules\Workspace\Reports;

use App\Models\User;
use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Search\WorkstationSearch;

/** Every desk with its whole patching record. */
class WorkstationReport extends Report
{
    public static function key(): string
    {
        return 'workstations';
    }

    public static function label(): string
    {
        return 'Workstations';
    }

    public static function description(): string
    {
        return 'Every workstation with its floor, status, switch port, VLAN, IP, MAC and PC.';
    }

    public static function group(): string
    {
        return 'Floors and network';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Workstation::class);
    }

    public function query(): Builder
    {
        return Workstation::query()
            ->with(['floor.building', 'area', 'switchPort.networkSwitch.rack', 'vlan'])
            ->withExists('mapObject as is_placed');
    }

    public function search(Builder $query, string $term): ?Builder
    {
        // The same search as Search Workstation, narrowed to the rows here.
        return $query->whereIn($query->getModel()->getQualifiedKeyName(), app(WorkstationSearch::class)->query($term)->reorder()->select('workstations.id'));
    }

    public function searchPlaceholder(): ?string
    {
        return 'Workstation, PC, switch, port, IP, MAC…';
    }

    public function defaultSort(): ?string
    {
        return 'name:asc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('name', 'Workstation ID', fn (Workstation $desk) => $desk->name)->sortable('name'),
            ReportColumn::make('floor', 'Floor', fn (Workstation $desk) => $desk->floor?->fullName()),
            ReportColumn::make('area', 'Area / Zone', fn (Workstation $desk) => $desk->area?->name)->hiddenByDefault(),
            ReportColumn::make('status', 'Status', fn (Workstation $desk) => $desk->status)->badge()->sortable('status'),
            ReportColumn::make('switch', 'Switch', fn (Workstation $desk) => $desk->networkSwitch()?->number),
            ReportColumn::make('port', 'Port', fn (Workstation $desk) => $desk->switchPort?->name)->mono(),
            ReportColumn::make('split', 'Port Split', fn (Workstation $desk) => $desk->port_split_number)->hiddenByDefault(),
            ReportColumn::make('rack', 'Rack', fn (Workstation $desk) => $desk->rack()?->number)->hiddenByDefault(),
            ReportColumn::make('vlan', 'VLAN', fn (Workstation $desk) => $desk->vlan?->number),
            ReportColumn::make('ip', 'IP Address', fn (Workstation $desk) => $desk->ip_address)->mono(),
            ReportColumn::make('mac', 'MAC Address', fn (Workstation $desk) => $desk->mac_address)->mono(),
            ReportColumn::make('pc', 'PC Name', fn (Workstation $desk) => $desk->computer_name),
            ReportColumn::make('pc_serial', 'PC Serial', fn (Workstation $desk) => $desk->pc_serial)->mono()->hiddenByDefault(),
            ReportColumn::make('placed', 'On Map', fn (Workstation $desk) => $desk->isPlaced()),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('floor_id')
                ->label('Floor')
                ->options(fn (): array => Floor::query()->with('building')->inBuildingOrder()->get()
                    ->mapWithKeys(fn (Floor $floor): array => [$floor->getKey() => $floor->fullName()])->all()),
            SelectFilter::make('status')->label('Status')->options(WorkstationStatus::class)->multiple(),
            SelectFilter::make('switch')
                ->label('Switch')
                ->options(fn (): array => NetworkSwitch::query()->orderBy('number')->get()->mapWithKeys(fn (NetworkSwitch $switch): array => [$switch->getKey() => $switch->label()])->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->onSwitch((int) $data['value']) : $query),
            SelectFilter::make('rack')
                ->label('Rack')
                ->options(fn (): array => Rack::query()->orderBy('number')->get()->mapWithKeys(fn (Rack $rack): array => [$rack->getKey() => $rack->label()])->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->inRack((int) $data['value']) : $query),
            SelectFilter::make('vlan_id')->label('VLAN')->relationship('vlan', 'number'),
            TernaryFilter::make('placed')
                ->label('On the map')
                ->queries(
                    true: fn (Builder $query): Builder => $query->placed(),
                    false: fn (Builder $query): Builder => $query->unplaced(),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ];
    }
}

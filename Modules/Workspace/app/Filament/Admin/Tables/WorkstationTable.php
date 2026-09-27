<?php

namespace Modules\Workspace\Filament\Admin\Tables;

use App\Filament\Actions\SpreadsheetExportAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rules\Unique;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Exports\WorkstationExport;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Filament\Admin\Schemas\WorkstationDetailFields;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;

/**
 * The pieces of a workstation list, shared by the cross-floor list and a
 * floor's own Workstations tab so the two cannot drift apart.
 *
 * `$floor` is the floor the list is standing on, when it is standing on one:
 * its columns and filters then leave out the floor and narrow the rest to it.
 */
class WorkstationTable
{
    /** @return array<int, mixed> */
    public static function columns(?Floor $floor = null): array
    {
        return array_values(array_filter([
            TextColumn::make('name')
                ->label('Workstation ID')
                ->weight('bold')
                ->searchable()
                ->sortable(),

            $floor ? null : TextColumn::make('floor.name')
                ->label('Floor')
                ->badge()
                ->sortable()
                ->description(fn (Workstation $record): ?string => $record->floor?->building?->name),

            TextColumn::make('area.name')
                ->label(Workstation::detailLabel('area'))
                ->sortable()
                ->placeholder('-')
                ->toggleable(),

            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->sortable(),

            IconColumn::make('placed')
                ->label('On plan')
                ->boolean()
                ->state(fn (Workstation $record): bool => $record->isPlaced())
                ->tooltip(fn (Workstation $record): string => $record->isPlaced()
                    ? 'On the floor map'
                    : 'Not yet on the floor map')
                ->toggleable(),

            // Location detail most people do not need in the list: a click
            // away in the column picker.
            self::text('desk_row')->toggleable(isToggledHiddenByDefault: true),
            self::text('desk_position')->toggleable(isToggledHiddenByDefault: true),
            self::text('workstation_number')->toggleable(isToggledHiddenByDefault: true),

            self::text('computer_name')->toggleable(),

            TextColumn::make('switchPort.networkSwitch.number')
                ->label(Workstation::detailLabel('switch'))
                ->searchable()
                ->placeholder('-')
                ->toggleable(),

            TextColumn::make('switchPort.name')
                ->label(Workstation::detailLabel('port'))
                ->searchable()
                ->fontFamily(FontFamily::Mono)
                ->placeholder('-')
                ->toggleable(),

            self::text('port_split_number')->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('switchPort.networkSwitch.rack.number')
                ->label(Workstation::detailLabel('rack'))
                ->searchable()
                ->placeholder('-')
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('vlan.number')
                ->label(Workstation::detailLabel('vlan'))
                ->searchable()
                ->placeholder('-')
                ->toggleable(),

            self::text('ip_address')
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->copyMessage('IP address copied')
                ->toggleable(),

            self::text('mac_address')
                ->fontFamily(FontFamily::Mono)
                // The one field on the row that gets read out loud a pair of
                // digits at a time, so it is worth being able to take it
                // without transcribing it.
                ->copyable()
                ->copyMessage('MAC address copied')
                ->toggleable(),

            self::text('pc_serial')->toggleable(isToggledHiddenByDefault: true),
            self::text('monitor_serial')->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('updated_at')
                ->label('Updated')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')
                ->label('Added')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /** @return array<int, mixed> */
    public static function filters(?Floor $floor = null): array
    {
        $buildingId = $floor?->building_id;

        return array_values(array_filter([
            SelectFilter::make('status')
                ->label('Status')
                ->options(WorkstationStatus::class)
                ->multiple(),

            $floor ? null : SelectFilter::make('floor_id')
                ->label('Floor')
                ->options(fn (): array => Floor::query()
                    ->with('building')
                    ->inBuildingOrder()
                    ->get()
                    ->mapWithKeys(fn (Floor $floor): array => [$floor->getKey() => $floor->fullName()])
                    ->all())
                ->searchable(),

            $floor ? SelectFilter::make('area_id')
                ->label(Workstation::detailLabel('area'))
                ->relationship('area', 'name', fn (Builder $query) => $query->where('floor_id', $floor->getKey())) : null,

            SelectFilter::make('switch')
                ->label(Workstation::detailLabel('switch'))
                ->options(fn (): array => NetworkSwitch::query()
                    ->when($buildingId, fn (Builder $query) => $query->where('building_id', $buildingId))
                    ->with('building')
                    ->orderBy('number')
                    ->get()
                    ->mapWithKeys(fn (NetworkSwitch $switch): array => [
                        $switch->getKey() => $buildingId ? $switch->label() : "{$switch->building->name} · {$switch->label()}",
                    ])
                    ->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->onSwitch((int) $data['value'])
                    : $query),

            SelectFilter::make('rack')
                ->label(Workstation::detailLabel('rack'))
                ->options(fn (): array => Rack::query()
                    ->when($buildingId, fn (Builder $query) => $query->where('building_id', $buildingId))
                    ->with('building')
                    ->orderBy('number')
                    ->get()
                    ->mapWithKeys(fn (Rack $rack): array => [
                        $rack->getKey() => $buildingId ? $rack->label() : "{$rack->building->name} · {$rack->label()}",
                    ])
                    ->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->inRack((int) $data['value'])
                    : $query),

            SelectFilter::make('vlan_id')
                ->label(Workstation::detailLabel('vlan'))
                ->options(fn (): array => Vlan::query()
                    ->when($floor, fn (Builder $query) => $query->where('site_id', $floor->building?->site_id))
                    ->with('site')
                    ->orderBy('number')
                    ->get()
                    ->mapWithKeys(fn (Vlan $vlan): array => [
                        $vlan->getKey() => $floor ? $vlan->label() : "{$vlan->site->name} · {$vlan->label()}",
                    ])
                    ->all())
                ->searchable(),

            // "Which desks have we not documented yet" is the question this
            // list gets asked the moment the patching sheet starts going in.
            // Which fields count lives on the model, so adding a field to the
            // record does not quietly leave this filter behind.
            TernaryFilter::make('details')
                ->label('Has details')
                ->queries(
                    true: fn (Builder $query): Builder => $query->withDetails(),
                    false: fn (Builder $query): Builder => $query->withDetails(false),
                    blank: fn (Builder $query): Builder => $query,
                ),

            TernaryFilter::make('placed')
                ->label('On the plan')
                ->queries(
                    true: fn (Builder $query): Builder => $query->placed(),
                    false: fn (Builder $query): Builder => $query->unplaced(),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ]));
    }

    /** @return array<int, mixed> */
    public static function recordActions(): array
    {
        return [
            WorkstationDetailsAction::make(),
            EditAction::make(),
            self::duplicate(),
            DeleteAction::make(),
        ];
    }

    /**
     * Duplicate a desk: same floor, area, row, switch and VLAN — the parts
     * neighbouring desks share — under a new ID. What identifies one specific
     * machine or connection (the port, IP, MAC, PC and serials) is left for the
     * new desk to be given its own, and it starts off the plan.
     */
    public static function duplicate(): ReplicateAction
    {
        return ReplicateAction::make()
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->modalHeading(fn (Workstation $record): string => "Duplicate {$record->name}")
            ->modalDescription('The copy keeps the floor, area, row, split and VLAN. The port, IP, MAC, PC name and serial numbers are left empty, because no two desks share them.')
            ->excludeAttributes([
                'switch_port_id',
                'ip_address',
                'mac_address',
                'computer_name',
                'pc_serial',
                'monitor_serial',
                // Not a column: "on the map" as the list loaded it.
                'is_placed',
            ])
            ->fillForm(fn (Workstation $record): array => [
                'name' => self::nextFreeName($record),
                'desk_position' => $record->desk_position,
                'floor_id' => $record->floor_id,
            ])
            ->schema([
                // The copy lands on the floor the original is on, and the form
                // carries it so the name can be checked against that floor.
                // Filament gives a replicate form the model but not the record.
                Hidden::make('floor_id'),

                TextInput::make('name')
                    ->label('Workstation ID')
                    ->required()
                    ->maxLength(100)
                    // Said on the field rather than found out when the copy is
                    // saved; the guard below is the race the form cannot see.
                    ->unique(
                        Workstation::class,
                        'name',
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('floor_id', $get('floor_id')),
                    )
                    ->validationMessages(['unique' => 'Another desk on this floor is already called that.']),
                TextInput::make('desk_position')
                    ->label(Workstation::detailLabel('desk_position'))
                    ->maxLength(20),
            ])
            ->beforeReplicaSaved(function (Workstation $replica, array $data, ReplicateAction $action): void {
                $taken = Workstation::query()
                    ->where('floor_id', $replica->floor_id)
                    ->where('name', trim($data['name']))
                    ->exists();

                if ($taken) {
                    Notification::make()
                        ->title('"'.trim($data['name']).'" is already used on this floor')
                        ->danger()
                        ->send();

                    $action->halt();
                }

                $replica->fill([
                    'name' => trim($data['name']),
                    'desk_position' => $data['desk_position'] ?? null,
                ]);
            })
            ->successNotificationTitle('Workstation duplicated');
    }

    /**
     * WS-024 suggests WS-025, or the next number up that is free on the floor.
     * A name with no number on the end gets "-copy".
     */
    public static function nextFreeName(Workstation $record): string
    {
        if (! preg_match('/^(.*?)(\d+)$/', $record->name, $parts)) {
            return $record->name.'-copy';
        }

        [, $prefix, $digits] = $parts;
        $number = (int) $digits;

        do {
            $candidate = $prefix.str_pad((string) ++$number, strlen($digits), '0', STR_PAD_LEFT);
        } while (Workstation::query()->where('floor_id', $record->floor_id)->where('name', $candidate)->exists());

        return $candidate;
    }

    /** @return array<int, mixed> */
    public static function bulkActions(): array
    {
        return [
            BulkActionGroup::make([
                BulkAction::make('setStatus')
                    ->label('Change status')
                    ->icon(Heroicon::OutlinedTag)
                    ->schema([WorkstationDetailFields::status()])
                    ->authorizeIndividualRecords('update')
                    ->action(function (Collection $records, array $data): void {
                        $status = $data['status'] instanceof WorkstationStatus
                            ? $data['status']
                            : WorkstationStatus::from($data['status']);

                        // One at a time so each change reaches the audit log.
                        $records->each(fn (Workstation $desk) => $desk->update(['status' => $status]));

                        Notification::make()
                            ->title($records->count().' workstation(s) set to '.$status->getLabel())
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('setVlan')
                    ->label('Set VLAN')
                    ->icon(Heroicon::OutlinedSquare3Stack3d)
                    ->schema([
                        Select::make('vlan_id')
                            ->label('VLAN')
                            ->options(fn (): array => Vlan::query()->with('site')->orderBy('number')->get()
                                ->mapWithKeys(fn (Vlan $vlan): array => [$vlan->getKey() => "{$vlan->site->name} · {$vlan->label()}"])
                                ->all())
                            ->searchable()
                            ->placeholder('No VLAN'),
                    ])
                    ->authorizeIndividualRecords('update')
                    ->action(function (Collection $records, array $data): void {
                        $vlan = filled($data['vlan_id'] ?? null) ? Vlan::query()->find($data['vlan_id']) : null;
                        $skipped = 0;

                        foreach ($records->load('floor.building') as $desk) {
                            // A VLAN belongs to a site; a desk at another site
                            // cannot be on it.
                            if ($vlan && $desk->floor->building->site_id !== $vlan->site_id) {
                                $skipped++;

                                continue;
                            }

                            $desk->update(['vlan_id' => $vlan?->getKey()]);
                        }

                        Notification::make()
                            ->title(($records->count() - $skipped).' workstation(s) updated')
                            ->body($skipped > 0 ? "{$skipped} at a different site from that VLAN were left alone." : null)
                            ->status($skipped > 0 ? 'warning' : 'success')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                SpreadsheetExportAction::bulk(
                    fn (Collection $records, string $format) => WorkstationExport::download($records, $format),
                    'export',
                    Workstation::class,
                ),

                DeleteBulkAction::make(),
            ]),
        ];
    }

    private static function text(string $column): TextColumn
    {
        return TextColumn::make($column)
            ->label(Workstation::detailLabel($column))
            ->searchable()
            ->sortable()
            ->placeholder('-');
    }
}

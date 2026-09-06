<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

class WorkstationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // Grouped by floor, because a flat list of every desk in the
            // building is only useful if it is still shaped like the building.
            ->defaultGroup('floor.name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('floor.name')
                    ->label('Floor')
                    ->sortable()
                    ->badge(),

                IconColumn::make('placed')
                    ->label('On plan')
                    ->boolean()
                    ->state(fn (Workstation $record): bool => $record->isPlaced())
                    ->tooltip(fn (Workstation $record): string => $record->isPlaced()
                        ? "Placed at {$record->position_x}%, {$record->position_y}%"
                        : 'Not yet positioned on the floor plan'),

                // Every detail column is toggleable: eight of them plus the
                // name, floor and placement does not fit on a screen. The four
                // that identify one specific machine are on by default; the
                // location trio and the split are a click away in the column
                // picker, kept in the order they appear on the patching sheet.
                TextColumn::make('site_location')
                    ->label('Site Location')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('zone_number')
                    ->label('Zone')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('workstation_number')
                    ->label('WS No.')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('port_split_number')
                    ->label('Port Split')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('computer_name')
                    ->label('Computer')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('mac_address')
                    ->label('MAC')
                    ->searchable()
                    ->placeholder('-')
                    // The one field on the row that gets read out loud a pair
                    // of digits at a time, so it is worth being able to take
                    // it without transcribing it.
                    ->copyable()
                    ->copyMessage('MAC address copied')
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(),

                TextColumn::make('switch_number')
                    ->label('Switch')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('interface_number')
                    ->label('Interface')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // "Which desks have we not documented yet" is the question this
                // list gets asked the moment the patching sheet starts going in.
                // Which columns count lives on the model, so adding a field to
                // the record does not quietly leave this filter behind.
                TernaryFilter::make('details')
                    ->label('Has details')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->withDetails(),
                        false: fn (Builder $query): Builder => $query->withDetails(false),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('floor_id')
                    ->label('Floor')
                    ->options(fn (): array => Floor::query()
                        ->inBuildingOrder()
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No workstations yet');
    }
}

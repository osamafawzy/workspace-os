<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;

class FloorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Bottom of the building first, which is how anyone standing in it
            // would list the floors.
            ->defaultSort('level')
            ->columns([
                TextColumn::make('level')
                    ->label('Level')
                    ->sortable()
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): ?string => $record->description),

                TextColumn::make('width_m')
                    ->label('Size')
                    ->state(fn ($record): string => $record->width_m.' x '.$record->depth_m.' m')
                    ->description(fn ($record): string => number_format($record->area()).' m2')
                    ->toggleable(),

                TextColumn::make('workstations_count')
                    ->label('Workstations')
                    ->counts('workstations')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    // A floor holding more desks than it has room for is not an
                    // error - the rule of thumb can be wrong and whoever is
                    // placing them can see the real room - but it is worth
                    // saying out loud rather than leaving them to discover it
                    // when the pins pile up on top of each other.
                    ->color(fn ($record): ?string => $record->workstations_count > $record->deskCapacity()
                        ? 'warning'
                        : null)
                    ->tooltip(fn ($record): ?string => $record->workstations_count > $record->deskCapacity()
                        ? 'More desks than this floor comfortably holds (about '.number_format($record->deskCapacity()).').'
                        : null),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                Action::make('plan')
                    ->label('Plan')
                    ->icon(Heroicon::OutlinedMap)
                    ->url(fn ($record): string => FloorResource::getUrl('plan', ['record' => $record])),

                EditAction::make(),
                // Deleting a floor takes its desks with it, so say so before
                // the click rather than after.
                DeleteAction::make()
                    ->modalDescription(fn ($record): string => 'Deleting this floor also deletes its '
                        .$record->workstations()->count().' workstation(s). This cannot be undone.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No floors yet')
            ->emptyStateDescription('Add a floor first — workstations are always attached to one.');
    }
}

<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Workspace\Filament\Admin\Actions\AddManyWorkstations;
use Modules\Workspace\Filament\Admin\Schemas\WorkstationDetailFields;
use Modules\Workspace\Models\Workstation;

/**
 * The desks on this floor, on the floor's own screen.
 *
 * This is the primary way workstations get added: you are looking at the
 * floor, so the floor is not something you have to pick.
 */
class WorkstationsRelationManager extends RelationManager
{
    protected static string $relationship = 'workstations';

    protected static ?string $title = 'Workstations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // A name is all a workstation is today. Everything else it will
            // eventually carry — who sits there, what hardware is on it, where
            // it falls on the plan — is added once those things are decided,
            // and none of them should block recording that the desk exists.
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(100)
                ->placeholder('A-01')
                // Scoped to this floor: "A-01" on the second floor is a
                // different desk and a legitimate name.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('floor_id', $this->getOwnerRecord()->getKey()))
                ->helperText('Must be unique on this floor.'),

            ...WorkstationDetailFields::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('placed')
                    ->label('On plan')
                    ->boolean()
                    ->state(fn (Workstation $record): bool => $record->isPlaced())
                    // Nothing is placed yet by definition — the plans are not
                    // in. The column is here so that stops being invisible the
                    // day they are.
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
            ->headerActions([
                AddManyWorkstations::make(fn () => $this->getOwnerRecord()),
                CreateAction::make()->label('Add workstation'),
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
            ->emptyStateHeading('No workstations on this floor')
            ->emptyStateDescription('Add each desk that physically exists here.');
    }
}

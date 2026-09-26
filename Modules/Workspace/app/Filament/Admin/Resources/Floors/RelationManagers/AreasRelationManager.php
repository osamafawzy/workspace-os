<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The areas this floor is divided into: "Operations Floor", "Zone A",
 * "Training Room 2". A desk is placed in one.
 */
class AreasRelationManager extends RelationManager
{
    protected static string $relationship = 'areas';

    protected static ?string $title = 'Areas';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('Operations Floor')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('floor_id', $this->getOwnerRecord()->getKey()))
                    ->helperText('Must be unique on this floor.'),

                TextInput::make('code')
                    ->label('Code')
                    ->maxLength(30),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Name')->weight('bold')->searchable()->sortable()
                    ->description(fn ($record): ?string => $record->description),
                TextColumn::make('code')->label('Code')->placeholder('-'),
                TextColumn::make('workstations_count')->label('Workstations')->counts('workstations')->sortable()->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add area'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn ($record): string => $record->workstations()->count() > 0
                        ? 'The '.$record->workstations()->count().' workstation(s) in this area stay on the floor, without an area.'
                        : 'This area has no workstations.'),
            ])
            ->emptyStateHeading('No areas on this floor')
            ->emptyStateDescription('Divide the floor into the zones or rooms desks are found in.');
    }
}

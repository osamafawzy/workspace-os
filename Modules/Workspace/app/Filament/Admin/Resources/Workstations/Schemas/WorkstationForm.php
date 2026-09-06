<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Modules\Workspace\Filament\Admin\Schemas\WorkstationDetailFields;
use Modules\Workspace\Models\Floor;

class WorkstationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Workstation')
                ->description('A desk that physically exists on a floor.')
                ->columns(2)
                ->schema([
                    Select::make('floor_id')
                        ->label('Floor')
                        ->required()
                        ->options(fn (): array => Floor::query()
                            ->inBuildingOrder()
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        // The name uniqueness rule reads this field, so a
                        // changed floor has to re-run that check.
                        ->live(),

                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('A-01')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, Get $get) => $rule->where('floor_id', $get('floor_id')),
                        )
                        ->helperText('Must be unique on the selected floor.'),
                ]),

            Section::make('Details')
                ->description('Where the desk is, how it is patched, and what is plugged into it. All optional.')
                ->collapsed(fn ($record): bool => $record === null || ! $record->hasDetails())
                ->schema(WorkstationDetailFields::make()),
        ]);
    }
}

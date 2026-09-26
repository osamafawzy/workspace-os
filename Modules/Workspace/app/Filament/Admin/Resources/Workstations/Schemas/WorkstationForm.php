<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                ->columns(3)
                ->schema([
                    Select::make('floor_id')
                        ->label('Floor')
                        ->required()
                        ->options(fn (): array => Floor::query()
                            ->with('building')
                            ->inBuildingOrder()
                            ->get()
                            ->mapWithKeys(fn (Floor $floor): array => [$floor->getKey() => $floor->fullName()])
                            ->all())
                        ->searchable()
                        ->preload()
                        // The name uniqueness rule and every choice below read
                        // this field, so a changed floor has to re-run them.
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            // Areas belong to one floor, so the old one cannot
                            // come along.
                            $set('area_id', null);

                            // Switches and VLANs belong to a building and a
                            // site. Moving within the building keeps them.
                            $from = Floor::query()->with('building')->find($old);
                            $to = Floor::query()->with('building')->find($state);

                            if ($from?->building_id !== $to?->building_id) {
                                $set('switch_id', null);
                                $set('switch_port_id', null);
                            }

                            if ($from?->building?->site_id !== $to?->building?->site_id) {
                                $set('vlan_id', null);
                            }
                        }),

                    TextInput::make('name')
                        ->label('Workstation ID')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('WS-024')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, Get $get) => $rule->where('floor_id', $get('floor_id')),
                        )
                        ->helperText('Must be unique on the selected floor.'),

                    WorkstationDetailFields::status(),
                ]),

            Section::make('Details')
                ->description('Where the desk is, how it is patched, and what is on it. All optional.')
                ->schema(WorkstationDetailFields::make(
                    floorId: fn (Get $get): mixed => $get('floor_id'),
                    withStatus: false,
                )),
        ]);
    }
}

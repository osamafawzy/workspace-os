<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Filament\Admin\Actions\AddManyWorkstations;
use Modules\Workspace\Filament\Admin\Schemas\WorkstationDetailFields;
use Modules\Workspace\Filament\Admin\Tables\WorkstationTable;
use Modules\Workspace\Models\Floor;

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
            TextInput::make('name')
                ->label('Workstation ID')
                ->required()
                ->maxLength(100)
                ->placeholder('WS-024')
                // Scoped to this floor: "WS-024" on the second floor is a
                // different desk and a legitimate name.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('floor_id', $this->getOwnerRecord()->getKey()))
                ->helperText('Must be unique on this floor.'),

            ...WorkstationDetailFields::make(fn (): int => $this->getOwnerRecord()->getKey()),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Floor $floor */
        $floor = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['area', 'switchPort.networkSwitch.rack', 'vlan'])->withExists('mapObject as is_placed'))
            ->columns(WorkstationTable::columns($floor))
            ->filters(WorkstationTable::filters($floor))
            ->headerActions([
                AddManyWorkstations::make(fn () => $this->getOwnerRecord()),
                CreateAction::make()->label('Add workstation'),
            ])
            ->recordActions(WorkstationTable::recordActions())
            ->toolbarActions(WorkstationTable::bulkActions())
            ->emptyStateHeading('No workstations on this floor')
            ->emptyStateDescription('Add each desk that physically exists here.');
    }
}

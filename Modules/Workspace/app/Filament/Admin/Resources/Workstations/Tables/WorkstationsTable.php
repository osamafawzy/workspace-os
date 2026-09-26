<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Tables;

use Filament\Tables\Table;
use Modules\Workspace\Filament\Admin\Tables\WorkstationTable;

class WorkstationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // Grouped by floor, because a flat list of every desk in the
            // building is only useful if it is still shaped like the building.
            ->defaultGroup('floor.name')
            ->columns(WorkstationTable::columns())
            ->filters(WorkstationTable::filters())
            ->recordActions(WorkstationTable::recordActions())
            ->toolbarActions(WorkstationTable::bulkActions())
            ->emptyStateHeading('No workstations yet');
    }
}

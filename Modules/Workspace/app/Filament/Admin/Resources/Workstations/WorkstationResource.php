<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\CreateWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\EditWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Schemas\WorkstationForm;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Tables\WorkstationsTable;
use Modules\Workspace\Models\Workstation;

/**
 * Every desk in the building, across all floors.
 *
 * Day to day you add desks from the floor's own screen; this resource is the
 * flat view — search the whole building for one desk, or see the total.
 */
class WorkstationResource extends Resource
{
    protected static ?string $model = Workstation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'Workspace';

    public static function form(Schema $schema): Schema
    {
        return WorkstationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkstationsTable::configure($table);
    }

    /** Every row shows its floor, so load it with the page rather than per row. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('floor');
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return $record->displayLabel();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkstations::route('/'),
            'create' => CreateWorkstation::route('/create'),
            'edit' => EditWorkstation::route('/{record}/edit'),
        ];
    }
}

<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Filament\Admin\Pages\SearchWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\CreateWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\EditWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Schemas\WorkstationForm;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Tables\WorkstationsTable;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Search\WorkstationSearch;

/**
 * Every desk in the building, across all floors.
 *
 * Day to day you add desks from the floor's own screen; this resource is the
 * flat view — search the whole building for one desk, or see the total.
 */
class WorkstationResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Workstation::class;

    protected static string $navigationKey = 'workstations';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WorkstationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkstationsTable::configure($table);
    }

    /** Every row shows its floor, area, port and VLAN, so load them with the page rather than per row. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['floor.building', 'area', 'switchPort.networkSwitch.rack', 'vlan'])
            ->withExists('mapObject as is_placed');
    }

    /**
     * Ctrl+K finds a desk by anything written on it or its cable.
     *
     * Declared so Filament treats the resource as searchable; the search
     * itself is {@see WorkstationSearch}, the same one Search Workstation uses.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'workstation_number', 'computer_name', 'ip_address', 'mac_address', 'switchPort.name'];
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        $user = auth()->user();

        return app(WorkstationSearch::class)
            ->search($search, 10)
            ->map(fn (Workstation $desk): GlobalSearchResult => new GlobalSearchResult(
                title: $desk->displayLabel(),
                // A desk on a map is found by going to it; one that is not
                // yet is found in the search, where its details are.
                url: $desk->mapObject && $desk->floor && ($user?->can('view', $desk->floor) ?? false)
                    ? WorkstationDetailsAction::locateUrl($desk)
                    : SearchWorkstation::getUrl(['q' => $desk->name]),
                details: array_filter([
                    'Status' => $desk->status->getLabel(),
                    'PC' => $desk->computer_name,
                    'Port' => $desk->switchPort?->label(),
                    'IP' => $desk->ip_address,
                ]),
            ));
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

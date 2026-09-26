<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page as BasePage;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\CreateFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\EditFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\ListFloors;
use Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers\AreasRelationManager;
use Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers\WorkstationsRelationManager;
use Modules\Workspace\Filament\Admin\Resources\Floors\Schemas\FloorForm;
use Modules\Workspace\Filament\Admin\Resources\Floors\Tables\FloorsTable;
use Modules\Workspace\Models\Floor;

class FloorResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Floor::class;

    protected static string $navigationKey = 'floor-setup';

    protected static ?string $recordTitleAttribute = 'name';

    /** Tabs above the page, so the map gets the full width. */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return FloorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FloorsTable::configure($table);
    }

    /**
     * The desks live on the floor's own screen. Adding a workstation is
     * something you do while looking at the floor it belongs to, not by
     * picking a floor out of a dropdown on a separate page.
     */
    public static function getRelations(): array
    {
        return [
            WorkstationsRelationManager::class,
            AreasRelationManager::class,
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    /**
     * A floor has two screens — what it is, and what it looks like — so they
     * are tabs on the one record rather than two places to navigate between.
     *
     * @return array<int, NavigationItem>
     */
    public static function getRecordSubNavigation(BasePage $page): array
    {
        return $page->generateNavigationItems([
            EditFloor::class,
            FloorPlan::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFloors::route('/'),
            'create' => CreateFloor::route('/create'),
            'edit' => EditFloor::route('/{record}/edit'),
            'plan' => FloorPlan::route('/{record}/plan'),
        ];
    }
}

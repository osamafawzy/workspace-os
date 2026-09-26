<?php

namespace Modules\Workspace\Filament\Admin\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;
use Modules\Workspace\Models\Floor;

/**
 * Floor Management → Floor Mapping: every floor, and the way into its map.
 *
 * Today each floor opens the existing plan editor and the 3D view. Phase 2
 * replaces the plan with the isometric map editor behind the same entry point.
 */
class FloorMapping extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'floor-mapping';

    protected static ?string $slug = 'floor-mapping';

    protected static ?string $title = 'Floor Mapping';

    protected string $view = 'workspace::filament.pages.floor-mapping';

    public static function canAccess(): bool
    {
        return FloorResource::canViewAny();
    }

    /** @return Collection<int, Floor> */
    public function floors(): Collection
    {
        return Floor::query()
            ->inBuildingOrder()
            ->withCount([
                'workstations',
                'workstations as placed_count' => fn ($query) => $query->placed(),
            ])
            ->get();
    }

    public function planUrl(Floor $floor): string
    {
        return FloorResource::getUrl('plan', ['record' => $floor]);
    }
}

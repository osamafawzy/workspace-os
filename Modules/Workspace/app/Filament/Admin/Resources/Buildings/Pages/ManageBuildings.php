<?php

namespace Modules\Workspace\Filament\Admin\Resources\Buildings\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Workspace\Filament\Admin\Resources\Buildings\BuildingResource;

class ManageBuildings extends ManageRecords
{
    protected static string $resource = BuildingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New building'),
        ];
    }
}

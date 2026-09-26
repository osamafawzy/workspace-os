<?php

namespace Modules\Settings\Filament\Admin\Resources\Locations\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Settings\Filament\Admin\Resources\Locations\LocationResource;

class ManageLocations extends ManageRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New location'),
        ];
    }
}

<?php

namespace Modules\Assets\Filament\Admin\Resources\Manufacturers\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Assets\Filament\Admin\Resources\Manufacturers\ManufacturerResource;

class ManageManufacturers extends ManageRecords
{
    protected static string $resource = ManufacturerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New manufacturer'),
        ];
    }
}

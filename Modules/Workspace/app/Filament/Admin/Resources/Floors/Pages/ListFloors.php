<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;

class ListFloors extends ListRecords
{
    protected static string $resource = FloorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New floor'),
        ];
    }
}

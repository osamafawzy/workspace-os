<?php

namespace Modules\Workspace\Filament\Admin\Resources\Racks\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Workspace\Filament\Admin\Resources\Racks\RackResource;

class ManageRacks extends ManageRecords
{
    protected static string $resource = RackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New rack'),
        ];
    }
}

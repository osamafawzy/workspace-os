<?php

namespace Modules\Workspace\Filament\Admin\Resources\Switches\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Workspace\Filament\Admin\Resources\Switches\NetworkSwitchResource;

class ListNetworkSwitches extends ListRecords
{
    protected static string $resource = NetworkSwitchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New switch'),
        ];
    }
}

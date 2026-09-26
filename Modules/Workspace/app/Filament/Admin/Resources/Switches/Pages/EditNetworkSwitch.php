<?php

namespace Modules\Workspace\Filament\Admin\Resources\Switches\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Workspace\Filament\Admin\Resources\Switches\NetworkSwitchResource;

class EditNetworkSwitch extends EditRecord
{
    protected static string $resource = NetworkSwitchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

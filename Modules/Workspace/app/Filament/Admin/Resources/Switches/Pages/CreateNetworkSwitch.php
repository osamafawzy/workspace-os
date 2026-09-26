<?php

namespace Modules\Workspace\Filament\Admin\Resources\Switches\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Workspace\Filament\Admin\Resources\Switches\NetworkSwitchResource;

class CreateNetworkSwitch extends CreateRecord
{
    protected static string $resource = NetworkSwitchResource::class;

    /** Straight to the switch's own screen, where its ports are added next. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

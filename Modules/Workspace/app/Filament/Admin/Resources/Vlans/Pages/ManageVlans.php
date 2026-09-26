<?php

namespace Modules\Workspace\Filament\Admin\Resources\Vlans\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Workspace\Filament\Admin\Resources\Vlans\VlanResource;

class ManageVlans extends ManageRecords
{
    protected static string $resource = VlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New VLAN'),
        ];
    }
}

<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;

class CreateWorkstation extends CreateRecord
{
    protected static string $resource = WorkstationResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}

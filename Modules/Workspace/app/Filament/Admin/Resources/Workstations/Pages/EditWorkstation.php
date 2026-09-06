<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;

class EditWorkstation extends EditRecord
{
    protected static string $resource = WorkstationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}

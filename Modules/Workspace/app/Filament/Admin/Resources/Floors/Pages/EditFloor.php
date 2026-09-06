<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;

class EditFloor extends EditRecord
{
    protected static string $resource = FloorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

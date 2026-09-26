<?php

namespace Modules\Assets\Filament\Admin\Resources\UpdateReasons\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Assets\Filament\Admin\Resources\UpdateReasons\UpdateReasonResource;

class ManageUpdateReasons extends ManageRecords
{
    protected static string $resource = UpdateReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New reason'),
        ];
    }
}

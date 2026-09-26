<?php

namespace Modules\Assets\Filament\Admin\Resources\AssetModels\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Assets\Filament\Admin\Resources\AssetModels\AssetModelResource;

class ManageAssetModels extends ManageRecords
{
    protected static string $resource = AssetModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New model'),
        ];
    }
}

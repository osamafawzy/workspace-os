<?php

namespace Modules\Assets\Filament\Admin\Resources\AssetTypes\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Assets\Filament\Admin\Resources\AssetTypes\AssetTypeResource;

class ManageAssetTypes extends ManageRecords
{
    protected static string $resource = AssetTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New asset type'),
        ];
    }
}

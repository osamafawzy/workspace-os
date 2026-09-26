<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

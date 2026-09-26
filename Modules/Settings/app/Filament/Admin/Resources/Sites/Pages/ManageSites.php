<?php

namespace Modules\Settings\Filament\Admin\Resources\Sites\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Settings\Filament\Admin\Resources\Sites\SiteResource;

class ManageSites extends ManageRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New site'),
        ];
    }
}

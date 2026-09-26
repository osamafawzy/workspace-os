<?php

namespace Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;

class ListReleaseBatches extends ListRecords
{
    protected static string $resource = ReleaseBatchResource::class;

    public function getTitle(): string
    {
        return 'Release New Assets';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New data'),
        ];
    }
}

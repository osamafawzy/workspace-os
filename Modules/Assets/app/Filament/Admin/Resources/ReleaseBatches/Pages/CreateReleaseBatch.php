<?php

namespace Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;

class CreateReleaseBatch extends CreateRecord
{
    protected static string $resource = ReleaseBatchResource::class;

    protected static ?string $title = 'New data';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'created_by' => auth()->id(), 'created_by_name' => auth()->user()?->name];
    }

    /** Straight to the rows: a batch is only its header until they are added. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

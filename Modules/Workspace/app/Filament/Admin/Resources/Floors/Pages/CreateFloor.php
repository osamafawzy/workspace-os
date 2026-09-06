<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;

class CreateFloor extends CreateRecord
{
    protected static string $resource = FloorResource::class;

    /**
     * Straight to the floor's own screen after creating it, because the next
     * thing anyone does with a new floor is put desks on it — and that lives
     * on the edit page's Workstations tab.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\Floor;

class ListWorkstations extends ListRecords
{
    protected static string $resource = WorkstationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New workstation')
                // Without a floor there is nothing to attach a desk to, and a
                // form whose only required relationship has no options is a
                // dead end. Build the floor first.
                ->disabled(fn (): bool => ! Floor::query()->exists())
                ->tooltip(fn (): ?string => Floor::query()->exists() ? null : 'Add a floor first.'),
        ];
    }
}

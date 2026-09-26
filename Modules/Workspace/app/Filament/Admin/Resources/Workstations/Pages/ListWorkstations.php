<?php

namespace Modules\Workspace\Filament\Admin\Resources\Workstations\Pages;

use App\Filament\Actions\SpreadsheetExportAction;
use App\Filament\Pages\ImportData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Workspace\Exports\WorkstationExport;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Imports\WorkstationImporter;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

class ListWorkstations extends ListRecords
{
    protected static string $resource = WorkstationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->authorize('import', Workstation::class)
                ->url(fn (): string => ImportData::getUrl(['importer' => WorkstationImporter::key()])),

            SpreadsheetExportAction::make(
                fn ($query, string $format) => WorkstationExport::download($query, $format),
                'export',
                Workstation::class,
            ),

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

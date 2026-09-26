<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets\Pages;

use App\Filament\Actions\SpreadsheetExportAction;
use App\Filament\Pages\ImportData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Assets\Exports\AssetExport;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Imports\AssetImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetType;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string
    {
        return 'Search For Assets';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->authorize('import', Asset::class)
                ->url(fn (): string => ImportData::getUrl(['importer' => AssetImporter::key()])),

            SpreadsheetExportAction::make(
                fn ($query, string $format) => AssetExport::download($query, $format),
                'export',
                Asset::class,
            ),

            CreateAction::make()
                ->label('New asset')
                // An asset is always of some type; with none there is nothing
                // to pick in the form's first, required field.
                ->disabled(fn (): bool => ! AssetType::query()->exists())
                ->tooltip(fn (): ?string => AssetType::query()->exists() ? null : 'Add an asset type under Settings first.'),
        ];
    }
}

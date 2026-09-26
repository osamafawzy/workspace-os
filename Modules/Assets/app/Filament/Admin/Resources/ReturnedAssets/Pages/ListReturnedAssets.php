<?php

namespace Modules\Assets\Filament\Admin\Resources\ReturnedAssets\Pages;

use App\Filament\Actions\SpreadsheetExportAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Modules\Assets\Exports\ReturnedAssetExport;
use Modules\Assets\Filament\Admin\Pages\ReturnAssets;
use Modules\Assets\Filament\Admin\Resources\ReturnedAssets\ReturnedAssetResource;
use Modules\Assets\Models\AssetReturn;

class ListReturnedAssets extends ListRecords
{
    protected static string $resource = ReturnedAssetResource::class;

    public function getTitle(): string
    {
        return 'Search For Returned Assets';
    }

    protected function getHeaderActions(): array
    {
        return [
            SpreadsheetExportAction::make(
                fn ($query, string $format) => ReturnedAssetExport::download($query, $format),
                'export',
                AssetReturn::class,
            ),

            Action::make('return')
                ->label('Return assets')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (): bool => Gate::allows('return-assets'))
                ->url(fn (): string => ReturnAssets::getUrl()),
        ];
    }
}

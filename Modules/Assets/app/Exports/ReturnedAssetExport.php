<?php

namespace Modules\Assets\Exports;

use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Assets\Filament\Admin\Resources\ReturnedAssets\ReturnedAssetResource;
use Modules\Assets\Models\AssetReturn;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The returned-assets list, as filtered, to a spreadsheet. */
class ReturnedAssetExport
{
    /**
     * @param  Builder<AssetReturn>|Collection<int, AssetReturn>  $records
     */
    public static function download(Builder|Collection $records, string $format): BinaryFileResponse
    {
        $returns = $records instanceof Builder
            ? $records->with(ReturnedAssetResource::EAGER_LOADS)->lazy(500)
            : $records->loadMissing(ReturnedAssetResource::EAGER_LOADS);

        return Spreadsheet::download(
            'returned-assets-'.now()->format('Y-m-d-His'),
            $format,
            ['Returned', 'Serial Number', 'Asset Tag', 'Type', 'Model', 'Employee', 'OID', 'Condition', 'Brought Back By', 'Received By', 'Site', 'Location', 'Receipt', 'Notes'],
            (function () use ($returns): \Generator {
                foreach ($returns as $return) {
                    yield [
                        $return->returned_at?->format('Y-m-d H:i'),
                        $return->asset?->serial_number,
                        $return->asset?->asset_tag,
                        $return->asset?->assetType?->name,
                        $return->asset?->assetModel?->fullName(),
                        $return->employee?->name,
                        $return->employee?->oid,
                        $return->condition?->getLabel(),
                        $return->returned_by_name,
                        $return->received_by_name,
                        $return->site?->name,
                        $return->location?->name,
                        $return->handoverForm?->number,
                        $return->notes,
                    ];
                }
            })(),
        );
    }
}

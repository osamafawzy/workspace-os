<?php

namespace Modules\Assets\Exports;

use App\Support\Import\ImportColumn;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Assets\Imports\AssetImporter;
use Modules\Assets\Models\Asset;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Assets to a spreadsheet, under the importer's headings, so an export can be
 * corrected in Excel and imported back onto the same assets.
 */
class AssetExport
{
    /**
     * @param  Builder<Asset>|Collection<int, Asset>  $records
     */
    public static function download(Builder|Collection $records, string $format): BinaryFileResponse
    {
        $columns = app(AssetImporter::class)->columns();
        $relations = ['assetType', 'assetModel.manufacturer', 'site', 'location', 'account', 'employee'];

        $assets = $records instanceof Builder
            ? $records->with($relations)->lazy(500)
            : $records->loadMissing($relations);

        return Spreadsheet::download(
            'assets-'.now()->format('Y-m-d-His'),
            $format,
            [...array_map(fn (ImportColumn $column): string => $column->label, $columns), 'Assigned To (Name)', 'Updated'],
            (function () use ($assets, $columns): \Generator {
                foreach ($assets as $asset) {
                    yield [
                        ...array_map(fn (ImportColumn $column): mixed => self::value($asset, $column->field), $columns),
                        $asset->employee?->name,
                        $asset->updated_at?->format('Y-m-d H:i'),
                    ];
                }
            })(),
        );
    }

    protected static function value(Asset $asset, string $field): mixed
    {
        return match ($field) {
            'asset_type' => $asset->assetType?->name,
            'manufacturer' => $asset->assetModel?->manufacturer?->name,
            'model' => $asset->assetModel?->name,
            'status' => $asset->status?->getLabel(),
            'condition' => $asset->condition?->getLabel(),
            'site' => $asset->site?->name,
            'location' => $asset->location?->name,
            'account' => $asset->account?->name,
            'employee_oid' => $asset->employee?->oid,
            'purchase_date' => $asset->purchase_date?->format('Y-m-d'),
            'warranty_expires_at' => $asset->warranty_expires_at?->format('Y-m-d'),
            default => $asset->getAttribute($field),
        };
    }
}

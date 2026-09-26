<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\EditAsset;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\ListAssets;
use Modules\Assets\Filament\Admin\Resources\Assets\Pages\ViewAsset;
use Modules\Assets\Filament\Admin\Resources\Assets\Schemas\AssetForm;
use Modules\Assets\Filament\Admin\Resources\Assets\Schemas\AssetInfolist;
use Modules\Assets\Filament\Admin\Resources\Assets\Tables\AssetsTable;
use Modules\Assets\Models\Asset;

/**
 * Asset Management → Search For Assets: the register, searchable by anything
 * written on an asset or said about who has it.
 */
class AssetResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Asset::class;

    protected static string $navigationKey = 'search-assets';

    protected static ?string $recordTitleAttribute = 'serial_number';

    /** What every list row and profile shows, loaded with the page. */
    public const EAGER_LOADS = ['assetType', 'assetModel.manufacturer', 'site', 'location', 'account', 'employee'];

    public static function form(Schema $schema): Schema
    {
        return AssetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AssetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssetsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(self::EAGER_LOADS);
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['serial_number', 'asset_tag', 'computer_name'];
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        return static::getEloquentQuery()
            ->search($search)
            ->limit(10)
            ->get()
            ->map(fn (Asset $asset) => new GlobalSearchResult(
                title: $asset->displayLabel(),
                url: static::getUrl('view', ['record' => $asset]),
                details: array_filter([
                    'Tag' => $asset->asset_tag,
                    'Status' => $asset->status->getLabel(),
                    'With' => $asset->employee?->auditLabel(),
                ]),
            ));
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Asset $record */
        return $record->displayLabel();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'create' => CreateAsset::route('/create'),
            'view' => ViewAsset::route('/{record}'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }
}

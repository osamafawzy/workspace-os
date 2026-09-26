<?php

namespace Modules\Assets\Filament\Admin\Resources\AssetTypes;

use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Modules\Assets\Filament\Admin\Resources\AssetTypes\Pages\ManageAssetTypes;
use Modules\Assets\Models\AssetType;
use Modules\Settings\Filament\Admin\Support\LookupResource;

/** Settings → Asset Types. */
class AssetTypeResource extends LookupResource
{
    protected static ?string $model = AssetType::class;

    protected static string $navigationKey = 'asset-types';

    protected static function extraFields(): array
    {
        return [
            Toggle::make('has_computer_name')
                ->label('Has a computer name')
                ->helperText('Laptops and desktops: the asset form asks for the computer name.'),

            Toggle::make('is_headset')
                ->label('Headset')
                ->helperText('Assets of this type are listed on the headset screens and reports.'),
        ];
    }

    protected static function extraColumns(): array
    {
        return [
            IconColumn::make('has_computer_name')->label('Computer name')->boolean(),
            IconColumn::make('is_headset')->label('Headset')->boolean(),
            TextColumn::make('assets_count')->label('Assets')->counts('assets')->sortable(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAssetTypes::route('/'),
        ];
    }
}

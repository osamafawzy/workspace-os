<?php

namespace Modules\Assets\Filament\Admin\Resources\Manufacturers;

use Filament\Tables\Columns\TextColumn;
use Modules\Assets\Filament\Admin\Resources\Manufacturers\Pages\ManageManufacturers;
use Modules\Assets\Models\Manufacturer;
use Modules\Settings\Filament\Admin\Support\LookupResource;

/** Settings → Manufacturers. */
class ManufacturerResource extends LookupResource
{
    protected static ?string $model = Manufacturer::class;

    protected static string $navigationKey = 'manufacturers';

    protected static function extraColumns(): array
    {
        return [
            TextColumn::make('asset_models_count')->label('Models')->counts('assetModels')->sortable(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageManufacturers::route('/'),
        ];
    }
}

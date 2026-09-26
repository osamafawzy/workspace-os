<?php

namespace Modules\Settings\Filament\Admin\Resources\Sites;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Modules\Settings\Filament\Admin\Resources\Sites\Pages\ManageSites;
use Modules\Settings\Filament\Admin\Support\LookupResource;
use Modules\Settings\Models\Site;

class SiteResource extends LookupResource
{
    protected static ?string $model = Site::class;

    protected static string $navigationKey = 'sites';

    protected static function extraFields(): array
    {
        return [
            TextInput::make('city')->label('City')->maxLength(100),
        ];
    }

    protected static function extraColumns(): array
    {
        return [
            TextColumn::make('city')->label('City')->searchable()->sortable()->placeholder('-'),
            TextColumn::make('locations_count')->label('Locations')->counts('locations')->sortable(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSites::route('/'),
        ];
    }
}

<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Assets\Filament\Admin\Support\AssetFields;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asset')
                ->columns(3)
                ->schema([
                    AssetFields::type(),
                    AssetFields::model(),
                    AssetFields::status(),
                    AssetFields::serial(),
                    AssetFields::tag(),
                    AssetFields::condition(),
                    AssetFields::computerName(),
                ]),

            Section::make('Where it is and who has it')
                ->columns(2)
                ->schema([
                    AssetFields::site(),
                    AssetFields::location(),
                    AssetFields::account(),
                    AssetFields::employee()
                        ->helperText('For corrections. Handing an asset to somebody with a signed form is done with Assign.'),
                ]),

            Section::make('Purchase and warranty')
                ->columns(2)
                ->schema([
                    AssetFields::purchaseDate(),
                    AssetFields::warranty(),
                ]),

            Section::make('Notes')
                ->schema([
                    AssetFields::notes()->hiddenLabel(),
                ]),
        ]);
    }
}

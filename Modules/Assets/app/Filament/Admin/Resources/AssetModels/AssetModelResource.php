<?php

namespace Modules\Assets\Filament\Admin\Resources\AssetModels;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Modules\Assets\Filament\Admin\Resources\AssetModels\Pages\ManageAssetModels;
use Modules\Assets\Models\AssetModel;
use Modules\Settings\Filament\Admin\Support\LookupResource;

/** Settings → Asset Models: one manufacturer's product of one type. */
class AssetModelResource extends LookupResource
{
    protected static ?string $model = AssetModel::class;

    protected static string $navigationKey = 'asset-models';

    protected static function extraFields(): array
    {
        return [
            Select::make('manufacturer_id')
                ->label('Manufacturer')
                ->relationship('manufacturer', 'name', fn (Builder $query) => $query->orderBy('name'))
                ->required()
                ->searchable()
                ->preload()
                // Model names only have to be unique per manufacturer.
                ->live(),

            Select::make('asset_type_id')
                ->label('Asset type')
                ->relationship('assetType', 'name', fn (Builder $query) => $query->orderBy('name'))
                ->required()
                ->searchable()
                ->preload(),
        ];
    }

    /** "Latitude 5440" once per manufacturer. */
    protected static function uniqueNameScope(Unique $rule, Get $get): Unique
    {
        return $rule->where('manufacturer_id', $get('manufacturer_id'));
    }

    protected static function extraColumns(): array
    {
        return [
            TextColumn::make('manufacturer.name')->label('Manufacturer')->sortable()->searchable(),
            TextColumn::make('assetType.name')->label('Type')->badge()->sortable(),
            TextColumn::make('assets_count')->label('Assets')->counts('assets')->sortable(),
        ];
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['manufacturer', 'assetType']))
            ->filters([
                SelectFilter::make('manufacturer_id')->label('Manufacturer')->relationship('manufacturer', 'name'),
                SelectFilter::make('asset_type_id')->label('Type')->relationship('assetType', 'name'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAssetModels::route('/'),
        ];
    }
}

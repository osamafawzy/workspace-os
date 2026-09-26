<?php

namespace Modules\Settings\Filament\Admin\Resources\Locations;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Modules\Settings\Filament\Admin\Resources\Locations\Pages\ManageLocations;
use Modules\Settings\Filament\Admin\Support\LookupResource;
use Modules\Settings\Models\Location;

class LocationResource extends LookupResource
{
    protected static ?string $model = Location::class;

    protected static string $navigationKey = 'locations';

    protected static function extraFields(): array
    {
        return [
            Select::make('site_id')
                ->label('Site')
                ->relationship('site', 'name', fn (Builder $query) => $query->orderBy('name'))
                ->searchable()
                ->preload()
                // The name is only unique within a site, so a changed site
                // has to re-run that check.
                ->live(),
        ];
    }

    /** "Store Room" can exist at two sites; not twice at one. */
    protected static function uniqueNameScope(Unique $rule, Get $get): Unique
    {
        return filled($get('site_id'))
            ? $rule->where('site_id', $get('site_id'))
            : $rule->whereNull('site_id');
    }

    protected static function extraColumns(): array
    {
        return [
            TextColumn::make('site.name')->label('Site')->badge()->sortable()->placeholder('No site'),
        ];
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with('site'))
            ->filters([
                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLocations::route('/'),
        ];
    }
}

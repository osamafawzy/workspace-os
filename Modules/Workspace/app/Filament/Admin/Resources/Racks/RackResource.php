<?php

namespace Modules\Workspace\Filament\Admin\Resources\Racks;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\Workspace\Filament\Admin\Resources\Racks\Pages\ManageRacks;
use Modules\Workspace\Filament\Admin\Support\BuildingSelect;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Rack;

class RackResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Rack::class;

    protected static string $navigationKey = 'racks';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                BuildingSelect::make(),

                Select::make('floor_id')
                    ->label('Floor')
                    ->options(fn (Get $get): array => Floor::query()
                        ->where('building_id', $get('building_id'))
                        ->inBuildingOrder()
                        ->pluck('name', 'id')
                        ->all())
                    ->rules([fn (Get $get) => Rule::exists('floors', 'id')->where('building_id', $get('building_id'))])
                    ->placeholder('Not recorded')
                    ->helperText('Which floor it stands on, if known.'),

                TextInput::make('number')
                    ->label('Rack number')
                    ->required()
                    ->maxLength(50)
                    ->placeholder('RACK-02')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('building_id', $get('building_id')))
                    ->helperText('What is printed on it. Unique within the building.'),

                TextInput::make('name')
                    ->label('Rack name')
                    ->maxLength(100)
                    ->placeholder('IT room, east wing'),

                TextInput::make('location')->label('Location')->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building', 'floor']))
            ->columns([
                TextColumn::make('number')->label('Rack number')->weight('bold')->searchable()->sortable(),
                TextColumn::make('name')->label('Rack name')->searchable()->placeholder('-'),
                TextColumn::make('building.name')->label('Building')->badge()->sortable(),
                TextColumn::make('floor.name')->label('Floor')->placeholder('-')->sortable(),
                TextColumn::make('switches_count')->label('Switches')->counts('switches')->alignCenter()->sortable(),
                TextColumn::make('location')->label('Location')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('building_id')->label('Building')->relationship('building', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                // Hidden while switches are still in it.
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No racks yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRacks::route('/'),
        ];
    }
}

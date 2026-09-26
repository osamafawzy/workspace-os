<?php

namespace Modules\Workspace\Filament\Admin\Resources\Buildings;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Settings\Models\Site;
use Modules\Workspace\Filament\Admin\Resources\Buildings\Pages\ManageBuildings;
use Modules\Workspace\Models\Building;

/** The buildings on each site, which floors are then added to. */
class BuildingResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Building::class;

    protected static string $navigationKey = 'buildings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->createOptionForm([
                        TextInput::make('name')->label('Site name')->required()->maxLength(100),
                        TextInput::make('city')->label('City')->maxLength(100),
                    ])
                    ->createOptionUsing(fn (array $data): int => Site::query()->create($data)->getKey())
                    ->createOptionAction(fn ($action) => $action->authorize('create', Site::class))
                    ->helperText('Sites are managed under Settings → Sites.'),

                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('HQ Tower B')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('site_id', $get('site_id'))),

                TextInput::make('code')->label('Code')->maxLength(30),
                TextInput::make('address')->label('Address')->maxLength(255),

                Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),

                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('site'))
            ->columns([
                TextColumn::make('name')->label('Name')->weight('bold')->searchable()->sortable()
                    ->description(fn (Building $record): ?string => $record->address),
                TextColumn::make('site.name')->label('Site')->badge()->sortable(),
                TextColumn::make('code')->label('Code')->placeholder('-')->toggleable(),
                TextColumn::make('floors_count')->label('Floors')->counts('floors')->alignCenter()->sortable(),
                TextColumn::make('switches_count')->label('Switches')->counts('switches')->alignCenter()->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                // Hidden while floors, racks or switches are still in it.
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No buildings yet')
            ->emptyStateDescription('Add a building, then add its floors under Floor Setup.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBuildings::route('/'),
        ];
    }
}

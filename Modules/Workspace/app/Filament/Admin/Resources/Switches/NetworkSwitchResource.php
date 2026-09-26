<?php

namespace Modules\Workspace\Filament\Admin\Resources\Switches;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\Workspace\Filament\Admin\Resources\Switches\Pages\CreateNetworkSwitch;
use Modules\Workspace\Filament\Admin\Resources\Switches\Pages\EditNetworkSwitch;
use Modules\Workspace\Filament\Admin\Resources\Switches\Pages\ListNetworkSwitches;
use Modules\Workspace\Filament\Admin\Resources\Switches\RelationManagers\PortsRelationManager;
use Modules\Workspace\Filament\Admin\Support\BuildingSelect;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;

class NetworkSwitchResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = NetworkSwitch::class;

    protected static string $navigationKey = 'switches';

    protected static ?string $slug = 'switches';

    protected static ?string $modelLabel = 'switch';

    protected static ?string $pluralModelLabel = 'switches';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Switch')
                ->columns(2)
                ->schema([
                    BuildingSelect::make(),

                    Select::make('rack_id')
                        ->label('Rack')
                        ->options(fn (Get $get): array => Rack::query()
                            ->where('building_id', $get('building_id'))
                            ->orderBy('number')
                            ->get()
                            ->mapWithKeys(fn (Rack $rack): array => [$rack->getKey() => $rack->label()])
                            ->all())
                        ->rules([fn (Get $get) => Rule::exists('racks', 'id')->where('building_id', $get('building_id'))])
                        ->searchable()
                        ->placeholder('Not in a rack'),

                    TextInput::make('number')
                        ->label('Switch number')
                        ->required()
                        ->maxLength(50)
                        ->placeholder('SW-03')
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('building_id', $get('building_id')))
                        ->helperText('The label on the front. Unique within the building.'),

                    TextInput::make('name')
                        ->label('Switch name / hostname')
                        ->maxLength(100)
                        ->placeholder('ALX-F2-ACC-03'),

                    TextInput::make('model')->label('Model')->maxLength(100),
                    TextInput::make('serial_number')->label('Serial number')->maxLength(100),
                    TextInput::make('management_ip')->label('Management IP')->maxLength(45)->rules(['ip']),
                    TextInput::make('port_count')->label('Port count')->integer()->minValue(1)->maxValue(1000),

                    Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
                    Toggle::make('is_active')->label('Active')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building', 'rack']))
            ->columns([
                TextColumn::make('number')->label('Switch number')->weight('bold')->searchable()->sortable(),
                TextColumn::make('name')->label('Hostname')->searchable()->placeholder('-'),
                TextColumn::make('building.name')->label('Building')->badge()->sortable(),
                TextColumn::make('rack.number')->label('Rack')->placeholder('-')->sortable(),
                TextColumn::make('management_ip')->label('Management IP')->fontFamily(FontFamily::Mono)->placeholder('-')->toggleable(),
                TextColumn::make('model')->label('Model')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ports_count')->label('Ports')->counts('ports')->alignCenter(),
                TextColumn::make('workstations_count')->label('Patched desks')->counts('workstations')->alignCenter(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('building_id')->label('Building')->relationship('building', 'name'),
                SelectFilter::make('rack_id')->label('Rack')->relationship('rack', 'number'),
            ])
            ->recordActions([
                EditAction::make(),
                // Hidden while desks are patched into its ports.
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No switches yet');
    }

    public static function getRelations(): array
    {
        return [
            PortsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworkSwitches::route('/'),
            'create' => CreateNetworkSwitch::route('/create'),
            'edit' => EditNetworkSwitch::route('/{record}/edit'),
        ];
    }
}

<?php

namespace Modules\Workspace\Filament\Admin\Resources\Vlans;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Settings\Models\Site;
use Modules\Workspace\Filament\Admin\Resources\Vlans\Pages\ManageVlans;
use Modules\Workspace\Models\Vlan;

class VlanResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = Vlan::class;

    protected static string $navigationKey = 'vlans';

    protected static ?string $modelLabel = 'VLAN';

    protected static ?string $pluralModelLabel = 'VLANs';

    protected static ?string $recordTitleAttribute = 'number';

    /** 10.20.30.0/24 — an IPv4 network and a prefix length. */
    private const CIDR = '/^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}\/(3[0-2]|[12]?\d)$/';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => Site::query()->count() === 1 ? Site::query()->value('id') : null)
                    ->required()
                    ->searchable()
                    ->live()
                    ->helperText('VLAN 123 at one site is a different network from VLAN 123 at another.'),

                TextInput::make('number')
                    ->label('VLAN ID')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(4094)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('site_id', $get('site_id'))),

                TextInput::make('name')->label('Name')->maxLength(100)->placeholder('Operations'),

                TextInput::make('subnet')
                    ->label('Subnet')
                    ->maxLength(50)
                    ->placeholder('10.20.30.0/24')
                    ->regex(self::CIDR)
                    ->validationMessages(['regex' => 'Enter a network and prefix, e.g. 10.20.30.0/24.']),

                TextInput::make('gateway')->label('Gateway')->maxLength(45)->placeholder('10.20.30.1')->rules(['ip']),

                Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('site'))
            ->columns([
                TextColumn::make('number')->label('VLAN ID')->weight('bold')->searchable()->sortable(),
                TextColumn::make('name')->label('Name')->searchable()->placeholder('-'),
                TextColumn::make('site.name')->label('Site')->badge()->sortable(),
                TextColumn::make('subnet')->label('Subnet')->fontFamily(FontFamily::Mono)->placeholder('-'),
                TextColumn::make('gateway')->label('Gateway')->fontFamily(FontFamily::Mono)->placeholder('-')->toggleable(),
                TextColumn::make('workstations_count')->label('Workstations')->counts('workstations')->alignCenter()->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                // Hidden while desks are on it.
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No VLANs yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVlans::route('/'),
        ];
    }
}

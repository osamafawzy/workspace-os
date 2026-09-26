<?php

namespace Modules\Assets\Filament\Admin\Resources\ReleaseBatches;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Filament\Admin\Pages\PrintReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\CreateReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\EditReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\ListReleaseBatches;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages\ViewReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\RelationManagers\ItemsRelationManager;
use Modules\Assets\Filament\Admin\Support\AssetFields;
use Modules\Assets\Models\ReleaseBatch;

/**
 * Asset Management → Release New Assets.
 *
 * A delivery of new kit is staged here as "new data" — what it is, where it
 * lands, and one row per piece with the OID of who it is for — checked, then
 * released: the assets enter the register, go out with their handover forms,
 * and the batch is archived for reprinting.
 */
class ReleaseBatchResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = ReleaseBatch::class;

    protected static string $navigationKey = 'release-new-assets';

    protected static ?string $slug = 'release-new-assets';

    protected static ?string $modelLabel = 'release batch';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('New data')
                ->description('What this delivery is and where it goes. Every row below gets these.')
                ->columns(3)
                ->schema([
                    AssetFields::type(),
                    AssetFields::model(),
                    AssetFields::account(),
                    AssetFields::site(),
                    AssetFields::location(),
                    AssetFields::purchaseDate(),
                    AssetFields::warranty(),
                    AssetFields::notes()->columnSpan(2)->rows(2),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('New data')
                ->columns(4)
                ->schema([
                    TextEntry::make('number')->label('Batch')->fontFamily(FontFamily::Mono),
                    TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state))->color(fn (string $state): string => $state === ReleaseBatch::ARCHIVED ? 'success' : 'warning'),
                    TextEntry::make('assetType.name')->label('Type'),
                    TextEntry::make('assetModel.name')->label('Model')->state(fn (ReleaseBatch $record): ?string => $record->assetModel?->fullName())->placeholder('-'),
                    TextEntry::make('site.name')->label('Site')->placeholder('-'),
                    TextEntry::make('location.name')->label('Location')->placeholder('-'),
                    TextEntry::make('account.name')->label('Account')->placeholder('-'),
                    TextEntry::make('purchase_date')->label('Purchased')->date()->placeholder('-'),
                    TextEntry::make('created_by_name')->label('Staged by')->helperText(fn (ReleaseBatch $record): string => $record->created_at->format('Y-m-d H:i'))->placeholder('-'),
                    TextEntry::make('released_by_name')->label('Released by')->helperText(fn (ReleaseBatch $record): ?string => $record->released_at?->format('Y-m-d H:i'))->placeholder('-'),
                    TextEntry::make('print_count')->label('Report printed')->formatStateUsing(fn (int $state): string => $state === 0 ? 'Never' : $state.'×'),
                    TextEntry::make('notes')->label('Notes')->placeholder('-'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assetType', 'assetModel.manufacturer', 'site', 'location'])->withCount('items'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Batch')->fontFamily(FontFamily::Mono)->weight('bold')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === ReleaseBatch::ARCHIVED ? 'Released' : 'Draft')
                    ->color(fn (string $state): string => $state === ReleaseBatch::ARCHIVED ? 'success' : 'warning'),
                TextColumn::make('assetType.name')->label('New data')->description(fn (ReleaseBatch $record): ?string => $record->assetModel?->fullName()),
                TextColumn::make('items_count')->label('Rows')->sortable(),
                TextColumn::make('site.name')->label('Site')->description(fn (ReleaseBatch $record): ?string => $record->location?->name)->placeholder('-'),
                TextColumn::make('created_by_name')->label('Staged by')->description(fn (ReleaseBatch $record): string => $record->created_at->format('Y-m-d'))->placeholder('-'),
                TextColumn::make('released_at')->label('Released')->dateTime('Y-m-d H:i')->sortable()->placeholder('Not yet'),
            ])
            ->filters([
                SelectFilter::make('status')->options([ReleaseBatch::DRAFT => 'Drafts', ReleaseBatch::ARCHIVED => 'Released']),
            ])
            ->recordUrl(fn (ReleaseBatch $record): string => static::getUrl($record->isDraft() && (auth()->user()?->can('update', $record) ?? false) ? 'edit' : 'view', ['record' => $record]))
            ->recordActions([
                Action::make('print')
                    ->label(fn (ReleaseBatch $record): string => $record->isDraft() ? 'Quick print' : 'Re-print')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (ReleaseBatch $record): string => PrintReleaseBatch::getUrl(['batch' => $record->getKey()]))
                    ->openUrlInNewTab(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No new data yet')
            ->emptyStateDescription('Start a batch when a delivery of new assets arrives.');
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var ReleaseBatch $record */
        return $record->number.' · '.$record->describe();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReleaseBatches::route('/'),
            'create' => CreateReleaseBatch::route('/create'),
            'view' => ViewReleaseBatch::route('/{record}'),
            'edit' => EditReleaseBatch::route('/{record}/edit'),
        ];
    }
}

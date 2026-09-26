<?php

namespace Modules\Assets\Filament\Admin\Resources\ReturnedAssets;

use App\Filament\Actions\SpreadsheetExportAction;
use App\Models\User;
use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Exports\ReturnedAssetExport;
use Modules\Assets\Filament\Admin\Pages\PrintHandoverForm;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Filament\Admin\Resources\ReturnedAssets\Pages\ListReturnedAssets;
use Modules\Assets\Models\AssetReturn;
use Modules\Assets\Models\AssetType;
use Modules\Employees\Models\Employee;

/**
 * Asset Management → Search For Returned Assets: every return ever recorded,
 * searchable by who, what, when, where and in what state.
 */
class ReturnedAssetResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = AssetReturn::class;

    protected static string $navigationKey = 'returned-assets';

    protected static ?string $slug = 'returned-assets';

    protected static ?string $modelLabel = 'returned asset';

    public const EAGER_LOADS = ['asset.assetType', 'asset.assetModel.manufacturer', 'employee', 'site', 'location', 'handoverForm'];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(self::EAGER_LOADS))
            ->defaultSort('returned_at', 'desc')
            ->searchPlaceholder('Employee, OID, serial, tag, receiver…')
            ->columns([
                TextColumn::make('returned_at')
                    ->label('Returned')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search)),

                TextColumn::make('asset.serial_number')
                    ->label('Serial Number')
                    ->fontFamily(FontFamily::Mono)
                    ->weight('bold')
                    ->description(fn (AssetReturn $record): ?string => collect([$record->asset?->assetType?->name, $record->asset?->assetModel?->fullName()])->filter()->implode(' · ') ?: null)
                    ->url(fn (AssetReturn $record): ?string => $record->asset && (auth()->user()?->can('view', $record->asset) ?? false) ? AssetResource::getUrl('view', ['record' => $record->asset]) : null),

                TextColumn::make('asset.asset_tag')
                    ->label('Asset Tag')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('employee.name')
                    ->label('From')
                    ->description(fn (AssetReturn $record): ?string => $record->employee?->oid),

                TextColumn::make('condition')
                    ->label('Condition')
                    ->badge()
                    ->sortable(),

                TextColumn::make('returned_by_name')
                    ->label('Brought back by')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('received_by_name')
                    ->label('Received by')
                    ->placeholder('-'),

                TextColumn::make('location.name')
                    ->label('Put back at')
                    ->state(fn (AssetReturn $record): ?string => collect([$record->site?->name, $record->location?->name])->filter()->implode(' · ') ?: null)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('handoverForm.number')
                    ->label('Receipt')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('returned_at')
                    ->label('Returned')
                    ->schema([
                        DatePicker::make('from')->label('Returned from'),
                        DatePicker::make('until')->label('Returned until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('returned_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('returned_at', '<=', $date))),

                SelectFilter::make('condition')->label('Condition')->options(AssetCondition::class)->multiple(),

                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $employee): string => $employee->auditLabel())
                    ->searchable(),

                SelectFilter::make('received_by')
                    ->label('Received by')
                    ->options(fn (): array => User::query()->whereIn('id', AssetReturn::query()->select('received_by')->whereNotNull('received_by'))->orderBy('name')->pluck('name', 'id')->all()),

                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),

                SelectFilter::make('location_id')->label('Location')->relationship('location', 'name')->searchable()->preload(),

                SelectFilter::make('asset_type')
                    ->label('Type')
                    ->options(fn (): array => AssetType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('asset', fn (Builder $asset) => $asset->where('asset_type_id', $data['value']))
                        : $query),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                Action::make('receipt')
                    ->label('Receipt')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (AssetReturn $record): ?string => $record->handover_form_id ? PrintHandoverForm::getUrl(['form' => $record->handover_form_id]) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (AssetReturn $record): bool => $record->handoverForm !== null && (auth()->user()?->can('print', $record->handoverForm) ?? false)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    SpreadsheetExportAction::bulk(
                        fn ($records, string $format) => ReturnedAssetExport::download($records, $format),
                        'export',
                        AssetReturn::class,
                    ),
                ]),
            ])
            ->emptyStateHeading('Nothing returned yet');
    }

    /** One box: the employee's name or OID, the serial, the tag, who brought it, who received it. */
    public static function search(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))).'%';

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(returned_by_name) like ? escape '!'", [$like])
            ->orWhereRaw("lower(received_by_name) like ? escape '!'", [$like])
            ->orWhereHas('employee', fn (Builder $employee) => $employee
                ->whereRaw("lower(name) like ? escape '!'", [$like])
                ->orWhereRaw("lower(oid) like ? escape '!'", [$like]))
            ->orWhereHas('asset', fn (Builder $asset) => $asset
                ->whereRaw("lower(serial_number) like ? escape '!'", [$like])
                ->orWhereRaw("lower(asset_tag) like ? escape '!'", [$like])));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturnedAssets::route('/'),
        ];
    }
}

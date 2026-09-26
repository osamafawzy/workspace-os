<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets\Tables;

use App\Filament\Actions\SpreadsheetExportAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Exports\AssetExport;
use Modules\Assets\Filament\Admin\Pages\AssignAssets;
use Modules\Assets\Filament\Admin\Pages\ReturnAssets;
use Modules\Assets\Filament\Admin\Support\AssetFields;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\Manufacturer;
use Modules\Employees\Models\Employee;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->searchPlaceholder('Serial, tag, computer, employee, model, location…')
            ->columns([
                TextColumn::make('serial_number')
                    ->label('Serial Number')
                    ->weight('bold')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->sortable()
                    // One search box for every key; see Asset::scopeSearch().
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search)),

                TextColumn::make('asset_tag')
                    ->label('Asset Tag')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('assetType.name')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('assetModel.name')
                    ->label('Model')
                    ->state(fn (Asset $record): ?string => $record->assetModel?->fullName())
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('computer_name')
                    ->label('Computer Name')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                // Headsets arrive with a cord of their own; the rest never have one.
                TextColumn::make('cord_serial')
                    ->label('Cord S/N')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('condition')
                    ->label('Condition')
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('employee.name')
                    ->label('Assigned To')
                    ->description(fn (Asset $record): ?string => $record->employee?->oid)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('site.name')
                    ->label('Site')
                    ->description(fn (Asset $record): ?string => $record->location?->name)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('account.name')
                    ->label('Account')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('warranty_expires_at')
                    ->label('Warranty')
                    ->date()
                    ->sortable()
                    ->placeholder('-')
                    ->color(fn (Asset $record): ?string => match (true) {
                        $record->warranty_expires_at === null => null,
                        $record->warrantyExpired() => 'danger',
                        $record->warranty_expires_at->lte(today()->addDays(Asset::WARRANTY_WARNING_DAYS)) => 'warning',
                        default => null,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('purchase_date')
                    ->label('Purchased')
                    ->date()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(AssetStatus::class)->multiple(),

                SelectFilter::make('asset_type_id')->label('Type')->relationship('assetType', 'name')->multiple()->preload(),

                SelectFilter::make('manufacturer')
                    ->label('Manufacturer')
                    ->options(fn (): array => Manufacturer::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('assetModel', fn (Builder $model) => $model->where('manufacturer_id', $data['value']))
                        : $query),

                SelectFilter::make('asset_model_id')
                    ->label('Model')
                    ->options(fn (): array => AssetModel::query()->with('manufacturer')->orderBy('name')->get()
                        ->mapWithKeys(fn (AssetModel $model): array => [$model->getKey() => $model->fullName()])->all())
                    ->searchable(),

                SelectFilter::make('condition')->label('Condition')->options(AssetCondition::class),

                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),

                SelectFilter::make('location_id')->label('Location')->relationship('location', 'name')->searchable()->preload(),

                SelectFilter::make('account_id')->label('Account')->relationship('account', 'name')->searchable()->preload(),

                SelectFilter::make('employee_id')
                    ->label('Assigned to')
                    ->relationship('employee', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $employee): string => $employee->auditLabel())
                    ->searchable(),

                TernaryFilter::make('assigned')
                    ->label('With somebody')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('employee_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('employee_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('headsets')
                    ->label('Headsets')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->headsets(),
                        false: fn (Builder $query): Builder => $query->headsets(false),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('warranty')
                    ->label('Warranty')
                    ->options([
                        'expired' => 'Expired',
                        'expiring' => 'Ends within '.Asset::WARRANTY_WARNING_DAYS.' days',
                        'none' => 'Not recorded',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'expired' => $query->warrantyExpired(),
                        'expiring' => $query->warrantyExpiring(),
                        'none' => $query->whereNull('warranty_expires_at'),
                        default => $query,
                    }),

                Filter::make('purchase_date')
                    ->label('Purchased')
                    ->schema([
                        DatePicker::make('from')->label('Purchased from'),
                        DatePicker::make('until')->label('Purchased until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('purchase_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('purchase_date', '<=', $date))),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    // To the Assign and Return screens with this asset already
                    // on the list; the handover form is made there.
                    Action::make('assign')
                        ->label('Assign')
                        ->icon(Heroicon::OutlinedUserPlus)
                        ->url(fn (Asset $record): string => AssignAssets::getUrl(['asset' => $record->getKey()]))
                        ->visible(fn (Asset $record): bool => $record->isAssignable() && Gate::allows('assign-assets')),
                    Action::make('return')
                        ->label('Return')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->url(fn (Asset $record): string => ReturnAssets::getUrl(['asset' => $record->getKey()]))
                        ->visible(fn (Asset $record): bool => $record->employee_id !== null && Gate::allows('return-assets')),
                    Action::make('history')
                        ->label('History')
                        ->icon(Heroicon::OutlinedClock)
                        ->slideOver()
                        ->modalHeading(fn (Asset $record): string => $record->displayLabel())
                        ->modalDescription('Everything that has happened to this asset, newest first.')
                        ->modalContent(fn (Asset $record) => view('assets::filament.asset-history', ['entries' => $record->history()->limit(100)->get()]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setStatus')
                        ->label('Set status')
                        ->icon(Heroicon::OutlinedTag)
                        ->authorize(fn (): bool => auth()->user()?->hasPermission('assets.update') ?? false)
                        ->schema([AssetFields::status()->live(false)])
                        ->action(function (Collection $records, array $data): void {
                            $status = $data['status'] instanceof AssetStatus ? $data['status'] : AssetStatus::from($data['status']);
                            $skipped = 0;

                            foreach ($records as $asset) {
                                // Who holds an asset changes only by assigning
                                // and returning, which leave their papers.
                                if (! AssetFields::statusFits($status, $asset->employee_id !== null)) {
                                    $skipped++;

                                    continue;
                                }

                                $asset->update(['status' => $status]);
                            }

                            Notification::make()
                                ->title(($records->count() - $skipped)." asset(s) set to {$status->getLabel()}")
                                ->body($skipped ? "{$skipped} left as they were: use Assign or Return to change who holds an asset." : null)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('move')
                        ->label('Move')
                        ->icon(Heroicon::OutlinedMapPin)
                        ->authorize(fn (): bool => auth()->user()?->hasPermission('assets.update') ?? false)
                        ->schema([
                            AssetFields::site()->required(),
                            AssetFields::location(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $asset) {
                                $asset->update(['site_id' => $data['site_id'], 'location_id' => $data['location_id'] ?? null]);
                            }

                            Notification::make()->title($records->count().' asset(s) moved')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    SpreadsheetExportAction::bulk(
                        fn ($records, string $format) => AssetExport::download($records, $format),
                        'export',
                        Asset::class,
                    ),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No assets yet')
            ->emptyStateDescription('Add one, or import a spreadsheet of them.');
    }
}

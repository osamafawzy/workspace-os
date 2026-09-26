<?php

namespace Modules\Assets\Filament\Admin\Resources\HandoverForms;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Filament\Admin\Pages\PrintHandoverForm;
use Modules\Assets\Filament\Admin\Resources\HandoverForms\Pages\ListHandoverForms;
use Modules\Assets\Models\HandoverForm;
use Modules\Employees\Models\Employee;

/**
 * Asset Management → Handover Forms: every handover form and return receipt
 * ever made, to find and reprint. Nothing here can be edited or deleted.
 */
class HandoverFormResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = HandoverForm::class;

    protected static string $navigationKey = 'handover-forms';

    protected static ?string $modelLabel = 'handover form';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Number')
                    ->fontFamily(FontFamily::Mono)
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('number', 'like', '%'.$search.'%')
                        ->orWhereHas('employee', fn (Builder $employee) => $employee->where('name', 'like', '%'.$search.'%')->orWhere('oid', 'like', '%'.$search.'%'))))
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === HandoverForm::RETURN ? 'Return receipt' : 'Handover form')
                    ->color(fn (string $state): string => $state === HandoverForm::RETURN ? 'warning' : 'info'),

                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->description(fn (HandoverForm $record): ?string => $record->employee?->oid),

                TextColumn::make('assets')
                    ->label('Assets')
                    ->state(fn (HandoverForm $record): int => $record->assetCount()),

                TextColumn::make('generated_by_name')
                    ->label('By')
                    ->placeholder('-'),

                TextColumn::make('created_at')
                    ->label('Made')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('print_count')
                    ->label('Printed')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Never' : $state.'×')
                    ->description(fn (HandoverForm $record): ?string => $record->last_printed_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options([HandoverForm::HANDOVER => 'Handover forms', HandoverForm::RETURN => 'Return receipts']),

                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $employee): string => $employee->auditLabel())
                    ->searchable(),

                Filter::make('created_at')
                    ->label('Made')
                    ->schema([
                        DatePicker::make('from')->label('Made from'),
                        DatePicker::make('until')->label('Made until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('print')
                    ->label(fn (HandoverForm $record): string => $record->print_count ? 'Reprint' : 'Print')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (HandoverForm $record): string => PrintHandoverForm::getUrl(['form' => $record->getKey()]))
                    ->openUrlInNewTab()
                    ->visible(fn (HandoverForm $record): bool => auth()->user()?->can('print', $record) ?? false),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No forms yet')
            ->emptyStateDescription('A handover form is made each time assets are assigned, and a receipt each time they come back.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHandoverForms::route('/'),
        ];
    }
}

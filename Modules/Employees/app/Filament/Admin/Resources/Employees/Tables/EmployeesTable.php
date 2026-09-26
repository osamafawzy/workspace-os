<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Tables;

use App\Filament\Actions\SpreadsheetExportAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Exports\EmployeeExport;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\Employee;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns(array_values(array_filter([
                TextColumn::make('oid')
                    ->label('OID')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Employee $record): ?string => $record->job_title),

                TextColumn::make('employee_number')
                    ->label('Employee Number')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('department.name')
                    ->label('Department')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('account.name')
                    ->label('Account')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('site.name')
                    ->label('Site')
                    ->placeholder('-')
                    ->description(fn (Employee $record): ?string => $record->location?->name)
                    ->toggleable(),

                TextColumn::make('mobile')
                    ->label('Mobile')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Masked even for those allowed to see it: a list is read over
                // shoulders. The profile shows it in full.
                EmployeeResource::canViewSensitive() ? TextColumn::make('national_id_masked')
                    ->label('National ID')
                    ->state(fn (Employee $record): ?string => $record->maskedNationalId())
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true) : null,

                TextColumn::make('joined_at')
                    ->label('Joined')
                    ->date()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('left_at')
                    ->label('Left')
                    ->date()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])))
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(EmployeeStatus::class)
                    ->multiple(),

                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('account_id')
                    ->label('Account')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->preload(),

                SelectFilter::make('location_id')
                    ->label('Location')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('joined_at')
                    ->label('Joined')
                    ->schema([
                        DatePicker::make('from')->label('Joined from'),
                        DatePicker::make('until')->label('Joined until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('joined_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('joined_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setStatus')
                        ->label('Set status')
                        ->icon('heroicon-o-tag')
                        ->authorize(fn (): bool => auth()->user()?->hasPermission('employees.update') ?? false)
                        ->schema([
                            Select::make('status')->label('Status')->options(EmployeeStatus::class)->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $status = EmployeeStatus::from($data['status'] instanceof EmployeeStatus ? $data['status']->value : $data['status']);
                            $changed = 0;

                            foreach ($records as $employee) {
                                if (! auth()->user()?->can('update', $employee)) {
                                    continue;
                                }

                                $employee->update([
                                    'status' => $status,
                                    'left_at' => $status === EmployeeStatus::Left ? ($employee->left_at ?? now()) : $employee->left_at,
                                ]);
                                $changed++;
                            }

                            Notification::make()->title("{$changed} employee(s) set to {$status->getLabel()}")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    SpreadsheetExportAction::bulk(
                        fn ($records, string $format) => EmployeeExport::download($records, $format),
                        'export',
                        Employee::class,
                    ),

                    // Each one checked: somebody still holding assets stays.
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading('No employees yet')
            ->emptyStateDescription('Add one, or import the Workday export.');
    }
}

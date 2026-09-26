<?php

namespace Modules\Audit\Filament\Admin\Resources\AuditLogs;

use App\Models\AuditLog;
use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Audit\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;

class AuditLogResource extends Resource
{
    use HasConfigurableNavigation;

    protected static ?string $model = AuditLog::class;

    protected static string $navigationKey = 'audit-log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->description(fn (AuditLog $record): string => $record->created_at->diffForHumans()),

                TextColumn::make('user_name')
                    ->label('User')
                    ->searchable()
                    ->placeholder('System'),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'moved', 'arranged', 'plan cleared' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('record_label')
                    ->label('Record')
                    ->searchable()
                    ->description(fn (AuditLog $record): ?string => $record->recordType()
                        ? $record->recordType().' #'.$record->auditable_id
                        : null)
                    ->placeholder('-'),

                TextColumn::make('ip_address')
                    ->label('IP / host')
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (AuditLog $record): ?string => $record->hostname)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('module')
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('module')->pluck('module', 'module')->all()),

                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),

                SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                // One record's whole history. Other screens link here with it
                // filled in (?filters[record][type]=…&filters[record][id]=…).
                Filter::make('record')
                    ->label('Record')
                    ->schema([
                        Select::make('type')
                            ->label('Record type')
                            ->options(fn (): array => AuditLog::query()
                                ->whereNotNull('auditable_type')
                                ->distinct()
                                ->orderBy('auditable_type')
                                ->pluck('auditable_type')
                                ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
                                ->all()),
                        TextInput::make('id')
                            ->label('Record #')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['type'] ?? null, fn (Builder $query, string $type) => $query->where('auditable_type', $type))
                        ->when($data['id'] ?? null, fn (Builder $query, mixed $id) => $query->where('auditable_id', (int) $id)))
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['type'] ?? null) && blank($data['id'] ?? null)) {
                            return null;
                        }

                        return 'Record: '.trim(class_basename((string) ($data['type'] ?? '')).' #'.($data['id'] ?? ''), ' #');
                    }),

                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn (AuditLog $record): string => ucfirst($record->action).' · '.($record->record_label ?? $record->module))
                    ->modalContent(fn (AuditLog $record) => view('audit::changes', ['log' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('Nothing recorded yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}

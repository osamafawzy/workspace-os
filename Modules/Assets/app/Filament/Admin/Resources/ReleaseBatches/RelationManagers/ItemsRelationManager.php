<?php

namespace Modules\Assets\Filament\Admin\Resources\ReleaseBatches\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Filament\Admin\Pages\PrintHandoverForm;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\ReleaseBatchItem;
use Modules\Employees\Models\Employee;

/**
 * The New Data Table: one row per new asset. Editable while the batch is a
 * draft; after release, each row shows the asset it became and its form.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'New Data Table';

    /** Rows, and pasted lines, beyond this belong in a spreadsheet import. */
    public const MAX_ROWS = 1000;

    public function isReadOnly(): bool
    {
        /** @var ReleaseBatch $batch */
        $batch = $this->getOwnerRecord();

        return ! $batch->isDraft() || ! (auth()->user()?->can('update', $batch) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('serial_number')->label('Serial Number')->required()->maxLength(100)->autofocus(),
                TextInput::make('asset_tag')->label('Asset Tag')->maxLength(100),
                TextInput::make('computer_name')->label('Computer Name')->maxLength(100),
                TextInput::make('employee_oid')->label('Employee OID')->maxLength(50)->helperText('Leave empty to put it into stock.'),
                Select::make('condition')->label('Condition')->options(AssetCondition::class)->default(AssetCondition::New)->required(),
                Textarea::make('notes')->label('Notes')->rows(1),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('serial_number')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('serial_number')->label('Serial Number')->fontFamily(FontFamily::Mono)->weight('bold')->searchable()
                    ->url(fn (ReleaseBatchItem $record): ?string => $record->asset_id ? AssetResource::getUrl('view', ['record' => $record->asset_id]) : null),
                TextColumn::make('asset_tag')->label('Asset Tag')->fontFamily(FontFamily::Mono)->searchable()->placeholder('-'),
                TextColumn::make('computer_name')->label('Computer Name')->searchable()->placeholder('-'),
                TextColumn::make('employee_oid')
                    ->label('Employee')
                    ->searchable()
                    ->placeholder('Into stock')
                    ->description(fn (ReleaseBatchItem $record): ?string => $record->employee_oid
                        ? ($this->employeeNames()[mb_strtolower($record->employee_oid)] ?? 'Not found')
                        : null),
                TextColumn::make('condition')->label('Condition')->badge(),
                TextColumn::make('findings')
                    ->label('Checks')
                    ->state(fn (ReleaseBatchItem $record): array => $record->findings === null
                        ? ['Not checked']
                        : (collect($record->allFindings())->map(fn (array $finding): string => ($finding['level'] === 'error' ? '✗ ' : '• ').$finding['text'])->all() ?: ['✓ OK']))
                    ->listWithLineBreaks()
                    ->color(fn (ReleaseBatchItem $record): string => match (true) {
                        $record->findings === null => 'gray',
                        $record->hasErrors() => 'danger',
                        default => 'success',
                    })
                    ->wrap(),
                TextColumn::make('handoverForm.number')
                    ->label('Form')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->url(fn (ReleaseBatchItem $record): ?string => $record->handover_form_id ? PrintHandoverForm::getUrl(['form' => $record->handover_form_id]) : null, shouldOpenInNewTab: true)
                    ->visible(fn (): bool => $this->getOwnerRecord()->isArchived()),
            ])
            ->filters([
                TernaryFilter::make('problems')
                    ->label('Problems')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('findings', 'like', '%"error"%'),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query->whereNull('findings')->orWhere('findings', 'not like', '%"error"%')),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add New Data')
                    ->icon(Heroicon::OutlinedPlus)
                    ->createAnother()
                    ->before(fn (CreateAction $action) => $this->ensureRoom($action)),

                Action::make('paste')
                    ->label('Paste rows')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->color('gray')
                    ->hidden(fn (): bool => $this->isReadOnly())
                    ->modalHeading('Paste rows')
                    ->modalDescription('One asset per line: serial number, asset tag, computer name, employee OID — separated by tabs (as copied from Excel), commas or semicolons. Only the serial number is needed.')
                    ->schema([
                        Textarea::make('lines')->label('Rows')->rows(12)->required()->placeholder("5CG1234ABC\tAT-000123\tALX-LT-0123\t1234567"),
                    ])
                    ->action(fn (array $data) => $this->paste((string) $data['lines'])),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No rows yet')
            ->emptyStateDescription('Add the new assets one by one, or paste them from a spreadsheet.');
    }

    /**
     * Pasted lines as rows. A heading line ("Serial…") is skipped, blank lines
     * are ignored, and cells beyond the fourth are dropped.
     */
    public function paste(string $lines): void
    {
        abort_if($this->isReadOnly(), 403);

        /** @var ReleaseBatch $batch */
        $batch = $this->getOwnerRecord();
        $room = self::MAX_ROWS - $batch->items()->count();
        $added = 0;
        $skipped = 0;

        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map('trim', preg_split('/\t|;|,/', $line) ?: []);

            if (str_starts_with(mb_strtolower($cells[0] ?? ''), 'serial')) {
                continue;
            }

            if ($cells[0] === '' || mb_strlen($cells[0]) > 100 || $added >= $room) {
                $skipped++;

                continue;
            }

            $batch->items()->create([
                'serial_number' => $cells[0],
                'asset_tag' => mb_substr($cells[1] ?? '', 0, 100) ?: null,
                'computer_name' => mb_substr($cells[2] ?? '', 0, 100) ?: null,
                'employee_oid' => mb_substr($cells[3] ?? '', 0, 50) ?: null,
            ]);

            $added++;
        }

        Notification::make()
            ->title("{$added} row(s) added")
            ->body($skipped ? "{$skipped} line(s) skipped: no serial number, too long, or over the ".self::MAX_ROWS.'-row limit.' : 'Run the checks before releasing.')
            ->status($skipped ? 'warning' : 'success')
            ->send();
    }

    protected function ensureRoom(CreateAction $action): void
    {
        if ($this->getOwnerRecord()->items()->count() >= self::MAX_ROWS) {
            Notification::make()->title('This batch is full')->body('Start another batch for the rest.')->danger()->send();
            $action->halt();
        }
    }

    /** @return array<string, string> lower-cased OID => name, for the rows on the batch */
    protected function employeeNames(): array
    {
        return once(fn (): array => Employee::query()
            ->whereIn('oid', $this->getOwnerRecord()->items()->whereNotNull('employee_oid')->pluck('employee_oid'))
            ->get(['oid', 'name'])
            ->mapWithKeys(fn (Employee $employee): array => [mb_strtolower($employee->oid) => $employee->name])
            ->all());
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Import\ImportColumn;
use App\Support\Import\Importer;
use App\Support\Import\Importers;
use App\Support\Import\ImportFileException;
use App\Support\Import\ImportRunner;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importing a spreadsheet, for any importer: upload → check → preview → import.
 *
 * Nothing reaches the real tables until the preview has been seen. The check
 * stores every row with what it found — new, existing, duplicate, invalid —
 * and the Import button only ever writes the rows marked as importable,
 * checking each one again as it goes.
 */
class ImportData extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'import';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.import-data';

    #[Url]
    public string $importer = '';

    #[Url]
    public ?int $batch = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->authorizeImport();

        $this->form->fill([
            'existing' => ImportRunner::EXISTING_SKIP,
            ...$this->importerInstance()->defaultOptions(),
        ]);

        if ($this->batch !== null) {
            $this->batchRecord() ?? abort(404);
        }
    }

    public function getTitle(): string
    {
        return 'Import '.mb_strtolower($this->importerInstance()::label());
    }

    public function importerInstance(): Importer
    {
        return app(Importers::class)->get($this->importer) ?? abort(404);
    }

    protected function authorizeImport(): void
    {
        abort_unless(auth()->user() && $this->importerInstance()->authorize(auth()->user()), 403);
    }

    /** The batch in the URL — only ever one this user uploaded, for this importer. */
    public function batchRecord(): ?ImportBatch
    {
        if ($this->batch === null) {
            return null;
        }

        return ImportBatch::query()
            ->whereKey($this->batch)
            ->where('importer', $this->importer)
            ->where('user_id', auth()->id())
            ->first();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Spreadsheet')
                    ->description('Excel (.xlsx) or CSV. The first row must be the headings — download the template to see them. Nothing is imported until you have seen the check.')
                    ->schema([
                        FileUpload::make('file')
                            ->label('File')
                            ->disk('local')
                            ->directory('imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(10240)
                            ->storeFileNamesIn('file_name')
                            ->required(),

                        Radio::make('existing')
                            ->label('Rows that match a record already here')
                            ->options([
                                ImportRunner::EXISTING_SKIP => 'Leave the existing record as it is',
                                ImportRunner::EXISTING_UPDATE => 'Update it from the file — empty cells keep the current value',
                            ])
                            ->required(),

                        ...$this->importerInstance()->optionFields(),
                    ]),
            ]);
    }

    public function checkFile(): void
    {
        $this->authorizeImport();

        $state = $this->form->getState();
        $disk = Storage::disk('local');
        $path = $state['file'];
        $name = $state['file_name'] ?? basename($path);

        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xlsx', 'csv', 'txt'], true)) {
            $disk->delete($path);
            Notification::make()->title('Only .xlsx and .csv files can be imported')->danger()->send();

            return;
        }

        try {
            $batch = app(ImportRunner::class)->check(
                $this->importerInstance(),
                $disk->path($path),
                $name,
                collect($state)->except(['file', 'file_name'])->all(),
                auth()->user(),
            );
        } catch (ImportFileException $exception) {
            Notification::make()->title('This file cannot be imported')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        } finally {
            // The rows are in the import tables now; the upload is not needed.
            $disk->delete($path);
        }

        $this->batch = $batch->getKey();
        $this->resetTable();

        Notification::make()->title('File checked')->body('Review the rows below, then import.')->success()->send();
    }

    public function table(Table $table): Table
    {
        // Personal data never goes on the preview; it waits encrypted.
        $importer = $this->importerInstance();
        $shown = collect($importer->columns())->reject(fn (ImportColumn $column): bool => in_array($column->field, $importer->sensitiveFields(), true));
        $columns = $shown->filter(fn (ImportColumn $column): bool => $column->required)
            ->merge($shown->reject(fn (ImportColumn $column): bool => $column->required)->take(3));

        return $table
            ->query(fn () => ImportRow::query()->where('import_batch_id', $this->batchRecord()?->getKey() ?? 0))
            ->defaultSort('row_number')
            ->columns([
                TextColumn::make('row_number')->label('Row')->sortable(),
                TextColumn::make('status')
                    ->label('Result')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ImportRow::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => ImportRow::statusColors()[$state] ?? 'gray'),
                ...$columns->map(fn (ImportColumn $column): TextColumn => TextColumn::make('value_'.$column->field)
                    ->label($column->label)
                    ->state(fn (ImportRow $record): ?string => $record->data[$column->field] ?? null)
                    ->placeholder('-'))->all(),
                TextColumn::make('messages')
                    ->label('Notes')
                    ->state(fn (ImportRow $record): array => collect($record->messages ?? [])
                        ->map(fn (array $message): string => ($message['level'] === 'error' ? '✗ ' : '• ').$message['text'])
                        ->all())
                    ->listWithLineBreaks()
                    ->color(fn (ImportRow $record): ?string => $record->errors() ? 'danger' : null)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Result')->options(ImportRow::statusLabels()),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No rows');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(collect(Spreadsheet::FORMATS)->map(fn (string $label, string $format): Action => Action::make('template_'.$format)
                ->label($label)
                ->action(fn (): BinaryFileResponse => app(ImportRunner::class)->template($this->importerInstance(), $format)))->values()->all())
                ->label('Download template')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->button(),

            Action::make('import')
                ->label(fn (): string => 'Import '.number_format($this->importableCount()).' row(s)')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => (bool) $this->batchRecord()?->isChecked())
                ->disabled(fn (): bool => $this->importableCount() === 0)
                ->requiresConfirmation()
                ->modalDescription('Rows marked New are created and rows marked Will update are updated. Invalid and duplicate rows are left out.')
                ->action(function (): void {
                    $this->authorizeImport();

                    $batch = app(ImportRunner::class)->import($this->batchRecord() ?? abort(404), $this->importerInstance());
                    $result = $batch->summary['result'] ?? [];

                    $this->resetTable();

                    Notification::make()
                        ->title(($result[ImportRow::IMPORTED] ?? 0).' created, '.($result[ImportRow::UPDATED] ?? 0).' updated')
                        ->body(($result[ImportRow::FAILED] ?? 0) > 0 ? $result[ImportRow::FAILED].' row(s) failed — see the error report.' : null)
                        ->status(($result[ImportRow::FAILED] ?? 0) > 0 ? 'warning' : 'success')
                        ->send();
                }),

            Action::make('errorReport')
                ->label('Download error report')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('gray')
                ->visible(fn (): bool => $this->problemCount() > 0)
                ->action(fn (): BinaryFileResponse => app(ImportRunner::class)->errorReport($this->batchRecord() ?? abort(404), $this->importerInstance())),

            Action::make('startOver')
                ->label(fn (): string => $this->batchRecord()?->isChecked() ? 'Choose another file' : 'Import another file')
                ->color('gray')
                ->visible(fn (): bool => $this->batchRecord() !== null)
                ->action(function (): void {
                    $batch = $this->batchRecord();

                    if ($batch?->isChecked()) {
                        $batch->update(['status' => ImportBatch::CANCELLED]);
                    }

                    $this->batch = null;
                    $this->form->fill([
                        'existing' => ImportRunner::EXISTING_SKIP,
                        ...$this->importerInstance()->defaultOptions(),
                    ]);
                }),

            Action::make('back')
                ->label('Back to the list')
                ->color('gray')
                ->visible(fn (): bool => $this->batchRecord()?->status === ImportBatch::IMPORTED && $this->importerInstance()->returnUrl() !== null)
                ->url(fn (): ?string => $this->importerInstance()->returnUrl()),
        ];
    }

    public function importableCount(): int
    {
        $batch = $this->batchRecord();

        return $batch ? $batch->count(ImportRow::NEW) + $batch->count(ImportRow::UPDATE) : 0;
    }

    public function problemCount(): int
    {
        return (int) $this->batchRecord()?->rows()
            ->whereIn('status', [ImportRow::INVALID, ImportRow::DUPLICATE, ImportRow::FAILED])
            ->count();
    }
}

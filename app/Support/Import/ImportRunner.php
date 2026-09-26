<?php

namespace App\Support\Import;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Checks a spreadsheet, then — once somebody has seen the result — imports it.
 *
 * Runs in the request rather than on a queue: the internal server has no
 * worker process, and a few thousand rows check and import in seconds.
 */
class ImportRunner
{
    /** More rows than this is almost certainly the wrong file, and would be slow to preview. */
    public const MAX_ROWS = 5000;

    public const EXISTING_SKIP = 'skip';

    public const EXISTING_UPDATE = 'update';

    /** Marks a stored cell as encrypted; see {@see seal()}. */
    protected const SEALED = 'sealed:';

    public function __construct(protected AuditLogger $audit) {}

    /**
     * Reads and checks every row without writing any of them anywhere but the
     * import tables.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ImportFileException when the file as a whole cannot be imported
     */
    public function check(Importer $importer, string $path, string $fileName, array $options, ?User $user): ImportBatch
    {
        $options = [...$importer->defaultOptions(), ...$options];
        [$columns, $unknown, $rows] = $this->readFile($importer, $path, $fileName);

        $importer->prepare($options);

        return DB::transaction(function () use ($importer, $fileName, $options, $user, $columns, $unknown, $rows): ImportBatch {
            $batch = ImportBatch::query()->create([
                'user_id' => $user?->getKey(),
                'importer' => $importer::key(),
                'file_name' => $fileName,
                'status' => ImportBatch::CHECKED,
                'options' => $options,
            ]);

            /** @var array<string, int> $keys key => first row number */
            $keys = [];
            $counts = array_fill_keys([ImportRow::NEW, ImportRow::UPDATE, ImportRow::UNCHANGED, ImportRow::DUPLICATE, ImportRow::INVALID], 0);
            $now = now();
            $pending = [];

            foreach ($rows as $number => $data) {
                $check = new RowCheck;
                $importer->check($data, $check, $options);
                $status = $this->statusFor($check, $keys, $number, $options);

                $counts[$status]++;
                $pending[] = [
                    'import_batch_id' => $batch->getKey(),
                    'row_number' => $number,
                    'data' => json_encode($this->seal($importer, $data), JSON_UNESCAPED_UNICODE),
                    'status' => $status,
                    'messages' => $check->messages ? json_encode($check->messages, JSON_UNESCAPED_UNICODE) : null,
                    'record_id' => $check->existing?->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($pending) === 500) {
                    ImportRow::query()->insert($pending);
                    $pending = [];
                }
            }

            if ($pending !== []) {
                ImportRow::query()->insert($pending);
            }

            $batch->update(['summary' => [
                'total' => array_sum($counts),
                ...$counts,
                'columns' => array_values(array_map(fn (ImportColumn $column): string => $column->label, $columns)),
                'ignored_headings' => $unknown,
            ]]);

            return $batch;
        });
    }

    /**
     * Imports the rows the check found importable. Each is checked again first,
     * because the database may have changed since the preview was made.
     */
    public function import(ImportBatch $batch, Importer $importer): ImportBatch
    {
        abort_unless($batch->isChecked(), 409, 'This file has already been imported.');

        $options = $batch->options ?? [];
        $importer->prepare($options);

        $result = [ImportRow::IMPORTED => 0, ImportRow::UPDATED => 0, ImportRow::SKIPPED => 0, ImportRow::FAILED => 0];
        $keys = [];

        // By id, not by offset: each row's status changes as it goes, and
        // offset paging over a filter on that status skips every other page.
        $batch->rows()
            ->whereIn('status', [ImportRow::NEW, ImportRow::UPDATE])
            ->lazyById(200)
            ->each(function (ImportRow $row) use ($importer, $options, &$result, &$keys): void {
                $data = $this->unseal($importer, $row->data);
                $check = new RowCheck;
                $importer->check($data, $check, $options);
                $status = $this->statusFor($check, $keys, $row->row_number, $options);

                if (! in_array($status, [ImportRow::NEW, ImportRow::UPDATE], true)) {
                    $final = $status === ImportRow::UNCHANGED ? ImportRow::SKIPPED : ImportRow::FAILED;
                    $messages = $check->messages;

                    if ($status === ImportRow::UNCHANGED) {
                        $messages[] = ['level' => 'warning', 'text' => 'Created by somebody else since the file was checked; left as it is.'];
                    }

                    $row->update(['status' => $final, 'messages' => $messages ?: null]);
                    $result[$final]++;

                    return;
                }

                try {
                    $record = DB::transaction(fn () => $importer->save($data, $check->existing, $options));
                    $final = $status === ImportRow::UPDATE ? ImportRow::UPDATED : ImportRow::IMPORTED;

                    $row->update(['status' => $final, 'record_id' => $record->getKey(), 'messages' => $check->messages ?: null]);
                    $result[$final]++;
                } catch (Throwable $exception) {
                    Log::warning('Import row failed', ['batch' => $row->import_batch_id, 'row' => $row->row_number, 'exception' => $exception]);

                    $row->update([
                        'status' => ImportRow::FAILED,
                        'messages' => [...$check->messages, ['level' => 'error', 'text' => 'Could not be saved: '.$exception->getMessage()]],
                    ]);
                    $result[ImportRow::FAILED]++;
                }
            });

        // Rows the check already ruled out were never going to be imported.
        $result[ImportRow::SKIPPED] += $batch->count(ImportRow::UNCHANGED)
            + $batch->count(ImportRow::DUPLICATE)
            + $batch->count(ImportRow::INVALID);

        // The same outcome told the way people ask about it: how many made it,
        // how many were already there or twice in the file, how many were not
        // good enough.
        $result['duplicates'] = $batch->count(ImportRow::UNCHANGED) + $batch->count(ImportRow::DUPLICATE);
        $result['invalid'] = $batch->count(ImportRow::INVALID);

        $batch->update([
            'status' => ImportBatch::IMPORTED,
            'imported_at' => now(),
            'summary' => [...($batch->summary ?? []), 'result' => $result],
        ]);

        $this->audit->log('imported', 'Import', $batch, [], [
            'file' => $batch->file_name,
            ...$result,
        ], $importer::label().' import');

        return $batch;
    }

    /** Every row that did not make it in, with why, for fixing in the spreadsheet. */
    public function errorReport(ImportBatch $batch, Importer $importer, string $format = 'xlsx'): BinaryFileResponse
    {
        $statuses = [ImportRow::INVALID, ImportRow::DUPLICATE, ImportRow::FAILED, ImportRow::UNCHANGED, ImportRow::SKIPPED];
        $reveal = $importer->mayRevealSensitive(auth()->user());

        // Personal data this user may not see is left out of the report
        // altogether, columns and all.
        $columns = array_values(array_filter(
            $importer->columns(),
            fn (ImportColumn $column): bool => $reveal || ! in_array($column->field, $importer->sensitiveFields(), true),
        ));

        $rows = $batch->rows()
            ->whereIn('status', $statuses)
            ->orderBy('row_number')
            ->lazy()
            ->map(function (ImportRow $row) use ($importer, $columns): array {
                $data = $this->unseal($importer, $row->data);

                return [
                    $row->row_number,
                    ImportRow::statusLabels()[$row->status] ?? $row->status,
                    collect($row->messages ?? [])->pluck('text')->implode(' | '),
                    ...array_map(fn (ImportColumn $column): string => (string) ($data[$column->field] ?? ''), $columns),
                ];
            });

        return Spreadsheet::download(
            str($batch->file_name)->beforeLast('.')->append(' - problems')->toString(),
            $format,
            ['Row', 'Status', 'Problems', ...array_map(fn (ImportColumn $column): string => $column->label, $columns)],
            $rows,
        );
    }

    /** An empty sheet with the right headings and one example row. */
    public function template(Importer $importer, string $format = 'xlsx'): BinaryFileResponse
    {
        $columns = array_values(array_filter($importer->columns(), fn (ImportColumn $column): bool => $column->inTemplate));

        return Spreadsheet::download(
            $importer::label().' import template',
            $format,
            array_map(fn (ImportColumn $column): string => $column->label, $columns),
            [array_map(fn (ImportColumn $column): string => $column->example, $columns)],
        );
    }

    /**
     * A row as it is stored between check and import: the importer's sensitive
     * fields encrypted, everything else as it was.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    public function seal(Importer $importer, array $data): array
    {
        foreach ($importer->sensitiveFields() as $field) {
            if (($data[$field] ?? '') !== '') {
                $data[$field] = self::SEALED.Crypt::encryptString($data[$field]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    public function unseal(Importer $importer, array $data): array
    {
        foreach ($importer->sensitiveFields() as $field) {
            $value = (string) ($data[$field] ?? '');

            if (str_starts_with($value, self::SEALED)) {
                $data[$field] = Crypt::decryptString(substr($value, strlen(self::SEALED)));
            }
        }

        return $data;
    }

    /**
     * @param  array<string, int>  $keys  seen keys => row number, updated in place
     * @param  array<string, mixed>  $options
     */
    protected function statusFor(RowCheck $check, array &$keys, int $number, array $options): string
    {
        if ($check->hasErrors()) {
            return ImportRow::INVALID;
        }

        if ($check->key !== null) {
            if (isset($keys[$check->key])) {
                $check->error('Same record as row '.$keys[$check->key].' of this file.');

                return ImportRow::DUPLICATE;
            }

            $keys[$check->key] = $number;
        }

        if ($check->existing) {
            return ($options['existing'] ?? self::EXISTING_SKIP) === self::EXISTING_UPDATE
                ? ImportRow::UPDATE
                : ImportRow::UNCHANGED;
        }

        return ImportRow::NEW;
    }

    /**
     * The file's rows as field => value, with its headings matched to the
     * importer's columns.
     *
     * @return array{0: array<int, ImportColumn>, 1: list<string>, 2: array<int, array<string, string>>}
     */
    protected function readFile(Importer $importer, string $path, string $fileName): array
    {
        $map = null;
        $unknown = [];
        $rows = [];

        try {
            foreach (Spreadsheet::read($path, $fileName) as $number => $cells) {
                if ($map === null) {
                    if (array_filter($cells, fn (string $cell): bool => $cell !== '') === []) {
                        continue;
                    }

                    [$map, $unknown] = $this->matchHeadings($importer, $cells);

                    continue;
                }

                $data = [];
                foreach ($map as $index => $column) {
                    $data[$column->field] = $cells[$index] ?? '';
                }

                if (array_filter($data, fn (string $value): bool => $value !== '') === []) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new ImportFileException('The file has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.');
                }

                $rows[$number] = $data + array_fill_keys(array_map(fn (ImportColumn $c): string => $c->field, $importer->columns()), '');
            }
        } catch (ImportFileException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ImportFileException('The file could not be read. Save it as .xlsx or .csv and try again.', previous: $exception);
        }

        if ($map === null) {
            throw new ImportFileException('The file is empty.');
        }

        if ($rows === []) {
            throw new ImportFileException('The file has headings but no rows under them.');
        }

        return [$map, $unknown, $rows];
    }

    /**
     * @param  list<string>  $headings
     * @return array{0: array<int, ImportColumn>, 1: list<string>}
     */
    protected function matchHeadings(Importer $importer, array $headings): array
    {
        $columns = $importer->columns();
        $map = [];
        $unknown = [];

        foreach ($headings as $index => $heading) {
            if ($heading === '') {
                continue;
            }

            $column = collect($columns)->first(fn (ImportColumn $column): bool => $column->matches($heading));

            // Compared by field: the first column with a heading wins, and a
            // second heading meaning the same thing is reported as ignored.
            $mapped = collect($map)->pluck('field')->all();

            if ($column && ! in_array($column->field, $mapped, true)) {
                $map[$index] = $column;
            } else {
                $unknown[] = $heading;
            }
        }

        $mapped = collect($map)->pluck('field')->all();

        $missing = collect($columns)
            ->filter(fn (ImportColumn $column): bool => $column->required && ! in_array($column->field, $mapped, true))
            ->map(fn (ImportColumn $column): string => $column->label);

        if ($missing->isNotEmpty()) {
            throw new ImportFileException('The file is missing required column(s): '.$missing->implode(', ').'. Download the template to see the headings expected.');
        }

        return [$map, $unknown];
    }
}

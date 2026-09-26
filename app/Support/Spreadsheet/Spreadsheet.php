<?php

namespace App\Support\Spreadsheet;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reading and writing CSV and Excel files, entirely on this server.
 *
 * Built on openspout, which streams rows rather than loading whole workbooks
 * into memory, so a sheet of a few thousand desks does not need a large PHP
 * memory limit on the internal server.
 */
class Spreadsheet
{
    public const FORMATS = [
        'xlsx' => 'Excel (.xlsx)',
        'csv' => 'CSV (.csv)',
    ];

    /**
     * Characters a spreadsheet program treats as the start of a formula.
     *
     * A desk note typed as `=HYPERLINK(...)` would run as a formula the moment
     * somebody opened the export in Excel. Exported cells starting with one of
     * these get a leading apostrophe, which Excel shows as plain text, and the
     * importer strips that apostrophe again so a round trip changes nothing.
     */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Every row of the first sheet, as trimmed strings.
     *
     * @return Generator<int, list<string>> row number (1-based) => cells
     */
    public static function read(string $path, ?string $originalName = null): Generator
    {
        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader(self::csvOptions($path)),
            default => throw new InvalidArgumentException('Only .xlsx and .csv files can be read.'),
        };

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    yield $number => array_map(self::cellToString(...), $row->toArray());
                }

                // The first sheet is the data; anything after it is notes.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Writes a sheet to a temporary file and hands it to the browser.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $baseName, string $format, array $headers, iterable $rows): BinaryFileResponse
    {
        if (! array_key_exists($format, self::FORMATS)) {
            throw new InvalidArgumentException("Unknown format [{$format}].");
        }

        $path = tempnam(sys_get_temp_dir(), 'sheet');
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;

        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(self::safeCell(...), array_values($row))));
        }

        $writer->close();

        return response()
            ->download($path, $baseName.'.'.$format)
            ->deleteFileAfterSend();
    }

    /** A value a spreadsheet will not run as a formula. */
    public static function safeCell(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::FORMULA_STARTS, true) ? "'".$value : $value;
    }

    /** Undoes {@see safeCell()} on the way back in. */
    public static function unsafeCell(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], self::FORMULA_STARTS, true)
            ? substr($value, 1)
            : $value;
    }

    /**
     * A header as a comparable key: "PC Serial Number" and "pc_serial number"
     * both become "pcserialnumber".
     */
    public static function headerKey(string $header): string
    {
        // Excel's CSV export puts a byte-order mark before the first header.
        $header = preg_replace('/^\x{FEFF}/u', '', $header);

        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
    }

    protected static function cellToString(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            // Excel stores 123 as 123.0; the sheet said 123.
            is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX => (string) (int) $value,
            is_scalar($value) => (string) $value,
            default => '',
        };

        return self::unsafeCell(trim($text));
    }

    /**
     * Excel saves "CSV" with semicolons in much of the world, so the separator
     * is read off the first line rather than assumed.
     */
    protected static function csvOptions(string $path): CsvReaderOptions
    {
        $options = new CsvReaderOptions;
        $handle = fopen($path, 'r') ?: throw new InvalidArgumentException('The file could not be opened.');
        $first = (string) fgets($handle);
        fclose($handle);

        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);

        $options->FIELD_DELIMITER = (string) array_key_first($counts);

        return $options;
    }
}

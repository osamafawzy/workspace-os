<?php

namespace App\Support\Import;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Teaches the import pipeline one kind of record.
 *
 * The pipeline does the rest — reading the file, matching headings, running
 * {@see check()} on every row, spotting duplicates within the file, the
 * preview, the commit, the error report — so each importer is only the part
 * that is actually about its records.
 *
 * Checking must not write anything. The preview is the promise of what an
 * import will do, and it is made before anybody has said yes; anything that
 * needs creating (a missing area, a new switch) is created in {@see save()}.
 */
abstract class Importer
{
    /** The key in URLs and in the batches table, e.g. "workstations". */
    abstract public static function key(): string;

    /** e.g. "Workstations". */
    abstract public static function label(): string;

    /** @return list<ImportColumn> */
    abstract public function columns(): array;

    /** Whether this user may run this import at all. */
    abstract public function authorize(User $user): bool;

    /**
     * Look at one row: validate it, say what it is the same as, and find the
     * record it matches. Must not write.
     *
     * @param  array<string, string>  $row  field => value, blank as ''
     * @param  array<string, mixed>  $options
     */
    abstract public function check(array $row, RowCheck $check, array $options): void;

    /**
     * Write one checked row: create the record, or update {@see RowCheck::$existing}.
     *
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $options
     */
    abstract public function save(array $row, ?Model $existing, array $options): Model;

    /**
     * Extra choices on the upload screen — Filament form components whose
     * state arrives in `$options`.
     *
     * @return array<int, mixed>
     */
    public function optionFields(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function defaultOptions(): array
    {
        return [];
    }

    /**
     * Fields holding personal data. Between the check and the import a file's
     * rows wait in the database; these fields wait there encrypted, and the
     * error report leaves them out for anyone {@see mayRevealSensitive()} refuses.
     *
     * @return list<string>
     */
    public function sensitiveFields(): array
    {
        return [];
    }

    public function mayRevealSensitive(?User $user): bool
    {
        return true;
    }

    /** Called before a run of checks, so an importer can load its lookups once. */
    public function prepare(array $options): void {}

    /**
     * Where to send somebody once the import is done. The options the import
     * ran with are given, so an importer that imported into one record — a
     * release batch, say — can send them back to it.
     *
     * @param  array<string, mixed>  $options
     */
    public function returnUrl(array $options = []): ?string
    {
        return null;
    }
}

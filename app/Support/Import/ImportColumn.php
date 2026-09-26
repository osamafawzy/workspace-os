<?php

namespace App\Support\Import;

use App\Support\Spreadsheet\Spreadsheet;

/**
 * One column an importer understands.
 *
 * Spreadsheets arrive with whatever headings their author used, so a column
 * lists the other names it goes by — "PC Name", "Computer Name", "Hostname" —
 * and any of them, in any case and spacing, is recognised.
 *
 * A column can be understood without being asked for: one with
 * `inTemplate: false` is read when a file carries it, but is left out of the
 * downloadable template, which stays the shape the office actually fills in.
 */
final class ImportColumn
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly array $aliases = [],
        public readonly string $example = '',
        public readonly bool $inTemplate = true,
    ) {}

    /** Whether a spreadsheet heading means this column. */
    public function matches(string $header): bool
    {
        $key = Spreadsheet::headerKey($header);

        foreach ([$this->field, $this->label, ...$this->aliases] as $name) {
            if ($key === Spreadsheet::headerKey($name)) {
                return true;
            }
        }

        return false;
    }
}

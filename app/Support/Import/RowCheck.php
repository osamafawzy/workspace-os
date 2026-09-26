<?php

namespace App\Support\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * What an importer found when it looked at one row.
 *
 * An importer fills this in; the runner turns it into the row's status — a
 * row with an error is invalid, a row whose key an earlier row already used is
 * a duplicate, a row matching an existing record updates it or is left alone.
 */
final class RowCheck
{
    /** @var list<array{level: string, text: string}> */
    public array $messages = [];

    /**
     * What makes two rows the same record, e.g. "floor 3 / WS-024". Two rows
     * with the same key in one file are duplicates.
     */
    public ?string $key = null;

    /** The record this row matches, if one exists already. */
    public ?Model $existing = null;

    public function error(string $text): self
    {
        $this->messages[] = ['level' => 'error', 'text' => $text];

        return $this;
    }

    /** Something the import will do, or not do, that is worth knowing. */
    public function warning(string $text): self
    {
        $this->messages[] = ['level' => 'warning', 'text' => $text];

        return $this;
    }

    public function hasErrors(): bool
    {
        return collect($this->messages)->contains('level', 'error');
    }
}

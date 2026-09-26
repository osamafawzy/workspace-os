<?php

namespace App\Support\Reports;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One column of a report: what it is called, and how to read it off a row.
 *
 * The same reading is used on screen, in the Excel/CSV export and on the
 * printed page, so the three can never show different things.
 */
final class ReportColumn
{
    protected ?string $sortColumn = null;

    protected bool $mono = false;

    protected bool $badge = false;

    protected bool $hiddenByDefault = false;

    protected bool $sortRaw = false;

    protected bool $totalled = false;

    /**
     * @param  Closure(Model): mixed  $value
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        protected Closure $value,
    ) {}

    /** @param  Closure(Model): mixed  $value */
    public static function make(string $key, string $label, Closure $value): self
    {
        return new self($key, $label, $value);
    }

    /** Sortable, by this database column of the report's own table. */
    public function sortable(?string $column = null): self
    {
        $this->sortColumn = $column ?? $this->key;

        return $this;
    }

    /**
     * Sortable by something the query works out itself — a count, a sum — named
     * by its alias rather than by a column of the table.
     */
    public function sortableByAlias(string $alias): self
    {
        $this->sortColumn = $alias;
        $this->sortRaw = true;

        return $this;
    }

    /**
     * A number worth adding up: the screen and the printed page show the total
     * of this column under the rows.
     */
    public function totalled(): self
    {
        $this->totalled = true;

        return $this;
    }

    /** Read a character at a time: serials, MACs, IPs. */
    public function mono(): self
    {
        $this->mono = true;

        return $this;
    }

    /** Shown as a badge on screen: statuses and the like. */
    public function badge(): self
    {
        $this->badge = true;

        return $this;
    }

    /** Off on screen until somebody switches it on; always in exports. */
    public function hiddenByDefault(): self
    {
        $this->hiddenByDefault = true;

        return $this;
    }

    /** The value as text: enums as their label, dates as Y-m-d, nothing as null. */
    public function value(Model $record): ?string
    {
        $value = ($this->value)($record);

        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof \BackedEnum && method_exists($value, 'getLabel') => $value->getLabel(),
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    public function sortColumn(): ?string
    {
        return $this->sortColumn;
    }

    public function isMono(): bool
    {
        return $this->mono;
    }

    public function isBadge(): bool
    {
        return $this->badge;
    }

    public function isHiddenByDefault(): bool
    {
        return $this->hiddenByDefault;
    }

    public function sortsByAlias(): bool
    {
        return $this->sortRaw;
    }

    public function isTotalled(): bool
    {
        return $this->totalled;
    }
}

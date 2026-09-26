<?php

namespace App\Support\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One report: its rows, its columns, its filters and who may see it.
 *
 * A module adds a report by writing one of these and registering it from its
 * service provider — the Reports screens list it, show it as a searchable,
 * filterable table, export it to Excel or CSV and print it, with no page of
 * its own.
 */
abstract class Report
{
    /** The key in the URL, e.g. "asset-inventory". */
    abstract public static function key(): string;

    abstract public static function label(): string;

    /** What it answers, in a sentence, for the reports list. */
    abstract public static function description(): string;

    /** Which heading it is listed under: "Assets", "Floors", "Admin"… */
    abstract public static function group(): string;

    /**
     * Whether this user may see it — usually the permission to see the
     * records it lists. Seeing reports at all (`reports.view`) is checked too.
     */
    abstract public function authorize(User $user): bool;

    /**
     * The rows, with whatever the columns read loaded.
     *
     * @return Builder<Model>
     */
    abstract public function query(): Builder;

    /** @return list<ReportColumn> */
    abstract public function columns(): array;

    /**
     * Filament table filters, applied on screen, in exports and in print alike.
     *
     * @return array<int, mixed>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * The search box. Null when the report has none.
     *
     * @param  Builder<Model>  $query
     */
    public function search(Builder $query, string $term): ?Builder
    {
        return null;
    }

    public function searchPlaceholder(): ?string
    {
        return null;
    }

    /** "column:direction" the rows are in until somebody sorts them. */
    public function defaultSort(): ?string
    {
        return null;
    }

    /** Printed on the same sheet as the rows: portrait for narrow reports. */
    public function landscape(): bool
    {
        return count($this->columns()) > 6;
    }

    /**
     * A LIKE pattern for a typed search, with its wildcards escaped ("!").
     */
    protected static function like(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))).'%';
    }
}

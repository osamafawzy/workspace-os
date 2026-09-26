<?php

namespace App\Support\Reports;

use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A report, as filtered and sorted on screen, to Excel or CSV — every column,
 * including the ones switched off on screen.
 */
class ReportExport
{
    /**
     * @param  Builder<Model>  $query  the table's filtered, sorted query
     */
    public static function download(Report $report, Builder $query, string $format): BinaryFileResponse
    {
        $columns = $report->columns();
        $base = $query->getQuery();

        // Paging through the rows needs an order, and Laravel adds the table's
        // key when there is none. A report that groups its rows cannot be
        // ordered by that key, so it is ordered by what it groups instead.
        if (empty($base->orders) && filled($base->groups)) {
            foreach ($base->groups as $group) {
                $query->orderBy($group);
            }
        }

        return Spreadsheet::download(
            $report::key().'-'.now()->format('Y-m-d-His'),
            $format,
            array_map(fn (ReportColumn $column): string => $column->label, $columns),
            (function () use ($query, $columns): \Generator {
                foreach ($query->lazy(500) as $record) {
                    yield array_map(fn (ReportColumn $column): ?string => $column->value($record), $columns);
                }
            })(),
        );
    }
}

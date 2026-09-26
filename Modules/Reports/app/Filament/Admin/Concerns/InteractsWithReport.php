<?php

namespace Modules\Reports\Filament\Admin\Concerns;

use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use App\Support\Reports\Reports;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A report as a Filament table, shared by the screen and the printed page.
 *
 * Search, filters and sort live in the address bar, so the print page — the
 * same table drawn as paper — opens with exactly what the screen was showing.
 *
 * @mixin InteractsWithTable
 */
trait InteractsWithReport
{
    public string $reportKey = '';

    // The pages using this declare $tableFilters, $tableSearch and $tableSort
    // themselves with #[Url], as Filament's own list pages do: a property
    // declared in two traits would leave it unclear whose attribute counts.

    protected ?Report $resolvedReport = null;

    public function report(): Report
    {
        return $this->resolvedReport ??= app(Reports::class)->get($this->reportKey) ?? abort(404);
    }

    protected function authorizeReport(string $key): void
    {
        $this->reportKey = $key;
        $user = auth()->user();

        abort_unless($user && app(Reports::class)->canView($user, $this->report()), 403);
    }

    public function reportTable(Table $table): Table
    {
        $report = $this->report();
        // A report has a search box when it says how to search.
        $searchable = (new \ReflectionMethod($report, 'search'))->getDeclaringClass()->getName() === Report::class;

        $columns = array_map(function (ReportColumn $column) use ($report, &$searchable): TextColumn {
            $text = TextColumn::make('report_'.$column->key)
                ->label($column->label)
                ->state(fn ($record): ?string => $column->value($record))
                ->placeholder('-')
                ->wrap()
                ->toggleable(isToggledHiddenByDefault: $column->isHiddenByDefault());

            if ($column->sortColumn()) {
                $text->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    // An alias the query worked out (a count) is not a column
                    // of the table and must not be qualified with its name.
                    $column->sortsByAlias() ? $column->sortColumn() : $query->getModel()->qualifyColumn($column->sortColumn()),
                    $direction,
                ));
            }

            if ($column->isTotalled()) {
                // Summed over the whole report, not over the page, and through
                // a subquery so a grouped report totals its groups.
                $text->summarize(Summarizer::make()
                    ->label('Total')
                    ->using(fn (QueryBuilder $query): int => (int) DB::query()
                        ->fromSub($query->clone()->reorder(), 'report_rows')
                        ->sum($column->sortColumn() ?? $column->key)));
            }

            if ($column->isMono()) {
                $text->fontFamily(FontFamily::Mono);
            }

            if ($column->isBadge()) {
                $text->badge()->color('gray');
            }

            // The report's own search, carried by its first column.
            if (! $searchable) {
                $text->searchable(query: fn (Builder $query, string $search): Builder => $report->search($query, $search) ?? $query);
                $searchable = true;
            }

            return $text;
        }, $report->columns());

        [$sortColumn, $sortDirection] = array_pad(explode(':', (string) $report->defaultSort()), 2, 'asc');

        // Which of the report's sorts name an alias the query works out rather
        // than a column of the table, so the default sort qualifies neither
        // wrongly.
        $aliases = array_map(fn (ReportColumn $column): string => (string) $column->sortColumn(), array_filter(
            $report->columns(),
            fn (ReportColumn $column): bool => $column->sortsByAlias(),
        ));

        // A report that groups its rows cannot also be ordered by the table's
        // key to settle ties: the key is not in the grouping, and strict SQL
        // modes refuse it. Its own default sort is the whole order.
        $grouped = filled($report->query()->getQuery()->groups);

        return $table
            ->query(fn (): Builder => $report->query())
            ->columns($columns)
            ->filters($report->filters())
            ->filtersFormColumns(3)
            ->searchPlaceholder($report->searchPlaceholder())
            ->defaultSort(filled($sortColumn) ? fn (Builder $query): Builder => $query->orderBy(
                in_array($sortColumn, $aliases, strict: true) ? $sortColumn : $query->getModel()->qualifyColumn($sortColumn),
                $sortDirection,
            ) : null)
            ->defaultKeySort(! $grouped)
            ->paginated([25, 50, 100, 250])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nothing matches');
    }
}

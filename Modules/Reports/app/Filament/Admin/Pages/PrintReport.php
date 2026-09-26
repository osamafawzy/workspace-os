<?php

namespace Modules\Reports\Filament\Admin\Pages;

use App\Support\Audit\AuditLogger;
use App\Support\Branding;
use App\Support\Reports\ReportColumn;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Modules\Reports\Filament\Admin\Concerns\InteractsWithReport;

/**
 * A report as paper: the same table as the screen — built from the same
 * search, filters and sort in the address bar — drawn for printing, and saved
 * as a PDF from the browser's print dialog like the handover forms.
 */
class PrintReport extends Page implements HasTable
{
    use InteractsWithReport;
    use InteractsWithTable;

    /** More rows than this is an export, not a printout. */
    public const MAX_ROWS = 2000;

    protected static ?string $slug = 'reports/{report}/print';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'reports::print.report';

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public function table(Table $table): Table
    {
        return $this->reportTable($table);
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->hasPermission('reports.view') ?? false)
            && (auth()->user()?->hasPermission('reports.print') ?? false);
    }

    public function mount(string $report): void
    {
        $this->authorizeReport($report);

        // The table is not built yet in mount(), so the filters are recorded
        // as they arrived rather than as their labels.
        app(AuditLogger::class)->log('printed report', 'Reports', null, [], array_filter([
            'report' => $this->report()::label(),
            'search' => $this->tableSearch ?: null,
            'filters' => array_filter($this->tableFilters ?? [], fn ($state) => filled(array_filter((array) $state, fn ($value) => filled($value)))) ?: null,
        ]), $this->report()::label());
    }

    public function getLayout(): string
    {
        return 'print.layout';
    }

    public function getTitle(): string
    {
        return $this->report()::label();
    }

    /** @return list<ReportColumn> */
    public function printColumns(): array
    {
        return $this->report()->columns();
    }

    /** @return Collection<int, Model> */
    public function rows(): Collection
    {
        return $this->getFilteredSortedTableQuery()->limit(self::MAX_ROWS + 1)->get();
    }

    public function total(): int
    {
        $query = $this->getFilteredTableQuery();

        // A report that groups its rows counts the groups, not the rows inside
        // them: counting a grouped query straight gives the first group's size.
        return filled($query->getQuery()->groups)
            ? DB::query()->fromSub($query->clone()->reorder(), 'report_rows')->count()
            : $query->count();
    }

    /** "Status: Assigned · Site: Alexandria", from the filter indicators. */
    public function activeFilterSummary(): string
    {
        return collect($this->getTable()->getFilters())
            ->flatMap(fn ($filter): array => $filter->getIndicators())
            ->map(fn ($indicator): string => (string) $indicator->getLabel())
            ->implode(' · ');
    }

    public function companyName(): string
    {
        return app(Branding::class)->companyName();
    }

    public function logoUrl(): ?string
    {
        return app(Branding::class)->logoUrl();
    }

    public function backUrl(): string
    {
        return ViewReport::getUrl(['report' => $this->reportKey, 'filters' => $this->tableFilters, 'search' => $this->tableSearch ?: null, 'sort' => $this->tableSort]);
    }
}

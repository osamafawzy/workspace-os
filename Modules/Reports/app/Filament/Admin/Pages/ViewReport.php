<?php

namespace Modules\Reports\Filament\Admin\Pages;

use App\Support\Reports\ReportExport;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use Modules\Reports\Filament\Admin\Concerns\InteractsWithReport;

/**
 * One report on screen: search, filter, sort, then export or print exactly
 * what is showing.
 */
class ViewReport extends Page implements HasTable
{
    use InteractsWithReport;
    use InteractsWithTable;

    protected static ?string $slug = 'reports/{report}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'reports::filament.pages.view-report';

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
        return auth()->user()?->hasPermission('reports.view') ?? false;
    }

    public function mount(string $report): void
    {
        $this->authorizeReport($report);
    }

    public function getTitle(): string
    {
        return $this->report()::label();
    }

    public function getSubheading(): ?string
    {
        return $this->report()::description();
    }

    public function getBreadcrumbs(): array
    {
        return [ReportsIndex::getUrl() => 'Reports', $this->report()::label()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->hasPermission('reports.export') ?? false)
                ->modalHeading('Export')
                ->modalDescription('Every row that matches the search and filters, with every column.')
                ->modalSubmitActionLabel('Download')
                ->schema([
                    Radio::make('format')->label('Format')->options(Spreadsheet::FORMATS)->default('xlsx')->required()->inline(),
                ])
                ->action(function (array $data) {
                    abort_unless(auth()->user()?->hasPermission('reports.export'), 403);

                    return ReportExport::download($this->report(), $this->getFilteredSortedTableQuery(), $data['format']);
                }),

            Action::make('print')
                ->label('Print / PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->visible(fn (): bool => auth()->user()?->hasPermission('reports.print') ?? false)
                ->url(fn (): string => PrintReport::getUrl([
                    'report' => $this->reportKey,
                    'filters' => $this->tableFilters,
                    'search' => $this->tableSearch ?: null,
                    'sort' => $this->tableSort,
                ]))
                ->openUrlInNewTab(),
        ];
    }
}

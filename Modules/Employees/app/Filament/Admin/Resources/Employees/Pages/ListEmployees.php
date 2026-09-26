<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Actions\SpreadsheetExportAction;
use App\Filament\Pages\ImportData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Employees\Exports\EmployeeExport;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Imports\EmployeeImporter;
use Modules\Employees\Models\Employee;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import from Workday')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->authorize('import', Employee::class)
                ->url(fn (): string => ImportData::getUrl(['importer' => EmployeeImporter::key()])),

            SpreadsheetExportAction::make(
                fn ($query, string $format) => EmployeeExport::download($query, $format),
                'export',
                Employee::class,
            ),

            CreateAction::make()->label('New employee'),
        ];
    }
}

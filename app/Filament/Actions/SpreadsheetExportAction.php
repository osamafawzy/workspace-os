<?php

namespace App\Filament\Actions;

use App\Support\Spreadsheet\Spreadsheet;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Export" on a list: the rows currently shown — search and filters applied —
 * to Excel or CSV, written on this server and downloaded straight away.
 *
 * Filament's own exporter needs a queue worker and database notifications,
 * neither of which the internal server runs; this does it in the request.
 */
class SpreadsheetExportAction
{
    /**
     * @param  Closure(Builder, string): mixed  $download  receives the filtered query and the format
     */
    public static function make(Closure $download, string $ability, string $model): Action
    {
        return Action::make('export')
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize($ability, $model)
            ->modalHeading('Export')
            ->modalDescription('Everything in the list as it is filtered and searched right now.')
            ->modalSubmitActionLabel('Download')
            ->schema([self::formatField()])
            ->action(fn (array $data, $livewire) => $download($livewire->getFilteredSortedTableQuery(), $data['format']));
    }

    /**
     * @param  Closure(Collection, string): mixed  $download  receives the selected records and the format
     */
    public static function bulk(Closure $download, string $ability, string $model): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->label('Export selected')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->authorize($ability, $model)
            ->modalSubmitActionLabel('Download')
            ->schema([self::formatField()])
            ->action(fn (Collection $records, array $data) => $download($records, $data['format']))
            ->deselectRecordsAfterCompletion();
    }

    protected static function formatField(): Radio
    {
        return Radio::make('format')
            ->label('Format')
            ->options(Spreadsheet::FORMATS)
            ->default('xlsx')
            ->required()
            ->inline();
    }
}

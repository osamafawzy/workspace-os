<?php

namespace Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Modules\Assets\Filament\Admin\Pages\PrintReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;
use Modules\Assets\Models\ReleaseBatch;

/** A released (archived) batch: what it was, what it made, and its reprints. */
class ViewReleaseBatch extends ViewRecord
{
    protected static string $resource = ReleaseBatchResource::class;

    public function getTitle(): string
    {
        /** @var ReleaseBatch $batch */
        $batch = $this->getRecord();

        return $batch->number.' · '.($batch->isArchived() ? 'Released' : 'Draft');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reprint')
                ->label(fn (): string => $this->getRecord()->isArchived() ? 'Re-Print Specific New Data Report' : 'Quick Print / PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => PrintReleaseBatch::getUrl(['batch' => $this->getRecord()->getKey()]))
                ->openUrlInNewTab(),

            EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) ?? false),
        ];
    }
}

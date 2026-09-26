<?php

namespace Modules\Assets\Filament\Admin\Resources\ReleaseBatches\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Actions\CheckReleaseBatch;
use Modules\Assets\Actions\ReleaseBatchAssets;
use Modules\Assets\Filament\Admin\Pages\PrintHandoverForm;
use Modules\Assets\Filament\Admin\Pages\PrintReleaseBatch;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;
use Modules\Assets\Models\ReleaseBatch;

/**
 * A draft batch: its header above, the New Data Table below (the items
 * relation manager), and the legacy application's buttons along the top.
 */
class EditReleaseBatch extends EditRecord
{
    protected static string $resource = ReleaseBatchResource::class;

    public function getTitle(): string
    {
        /** @var ReleaseBatch $batch */
        $batch = $this->getRecord();

        return "{$batch->number} · New data";
    }

    public function getSubheading(): ?string
    {
        /** @var ReleaseBatch $batch */
        $batch = $this->getRecord();
        $checks = collect(ReleaseBatch::CHECKS)
            ->map(function (string $label, string $key) use ($batch): string {
                $run = $batch->checks[$key] ?? null;

                return $run === null
                    ? "{$label}: not run"
                    : "{$label}: ".($run['problems'] ? $run['problems'].' problem(s)' : 'passed');
            });

        return $checks->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ...collect(ReleaseBatch::CHECKS)->map(fn (string $label, string $check): Action => Action::make('check_'.$check)
                    ->label($label)
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->action(fn () => $this->runChecks([$check])))->values()->all(),
                Action::make('checkAll')
                    ->label('Run all three checks')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->action(fn () => $this->runChecks(null)),
            ])
                ->label('Checks')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->button()
                ->color('gray'),

            Action::make('quickPrint')
                ->label('Quick Print / PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => PrintReleaseBatch::getUrl(['batch' => $this->getRecord()->getKey()]))
                ->openUrlInNewTab(),

            Action::make('release')
                ->label('Print New Data Forms and Archiving')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->authorize(fn (): bool => auth()->user()?->can('release', $this->getRecord()) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Release this batch?')
                ->modalDescription(function (): string {
                    /** @var ReleaseBatch $batch */
                    $batch = $this->getRecord();
                    $items = $batch->items()->get();
                    $people = $items->pluck('employee_oid')->filter()->unique()->count();

                    return "All three checks run again first. Then {$items->count()} asset(s) enter the register, "
                        .$items->whereNotNull('employee_oid')->count()." go to {$people} employee(s) with a handover form each, "
                        .$items->whereNull('employee_oid')->count().' go into stock, and the batch is archived. This cannot be undone here.';
                })
                ->modalSubmitActionLabel('Release and archive')
                ->action(function (): void {
                    try {
                        $forms = app(ReleaseBatchAssets::class)->handle($this->getRecord(), auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Not released')
                            ->body(collect($exception->errors())->flatten()->implode(' '))
                            ->danger()
                            ->persistent()
                            ->send();

                        $this->redirect(static::getUrl(['record' => $this->getRecord()]));

                        return;
                    }

                    Notification::make()
                        ->title("{$this->getRecord()->number} released")
                        ->body($forms->isEmpty() ? 'Everything went into stock.' : $forms->count().' handover form(s) ready to print: '.$forms->pluck('number')->implode(', '))
                        ->actions($forms->take(5)->map(fn ($form) => Action::make('print_'.$form->getKey())
                            ->label('Print '.$form->number)
                            ->url(PrintHandoverForm::getUrl(['form' => $form->getKey()]), shouldOpenInNewTab: true))->all())
                        ->success()
                        ->persistent()
                        ->send();

                    $this->redirect(ReleaseBatchResource::getUrl('view', ['record' => $this->getRecord()]));
                }),

            DeleteAction::make(),
        ];
    }

    /** @param  list<string>|null  $checks */
    protected function runChecks(?array $checks): void
    {
        /** @var ReleaseBatch $batch */
        $batch = $this->getRecord();
        $problems = app(CheckReleaseBatch::class)->handle($batch, $checks, auth()->user());
        $total = array_sum($problems);

        Notification::make()
            ->title($total === 0 ? 'No problems found' : "{$total} problem(s) found")
            ->body(collect($problems)->map(fn (int $count, string $check): string => ReleaseBatch::CHECKS[$check].': '.($count ? "{$count} row(s)" : 'passed'))->implode(' · '))
            ->status($total === 0 ? 'success' : 'warning')
            ->send();

        // The table below shows each row's findings.
        $this->redirect(static::getUrl(['record' => $batch]));
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getUrl(['record' => $this->getRecord()]);
    }
}

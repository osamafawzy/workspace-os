<?php

namespace Modules\Assets\Filament\Admin\Pages;

use App\Support\Audit\AuditLogger;
use App\Support\Branding;
use Filament\Pages\Page;
use Modules\Assets\Filament\Admin\Resources\HandoverForms\HandoverFormResource;
use Modules\Assets\Models\HandoverForm;
use Modules\Employees\Models\Employee;

/**
 * A handover form or return receipt, as paper.
 *
 * A page of the panel — so it is behind the same login and permissions — drawn
 * without the panel around it, sized for A4, and printed with the browser's own
 * print dialog, which also saves it as a PDF. That keeps it working with no
 * internet and no PDF library, and prints Arabic names as well as it shows them.
 *
 * It prints the frozen snapshot, so a reprint is the same paper as the first.
 * Every opening counts as a print; from the second on the paper says it is a
 * copy.
 */
class PrintHandoverForm extends Page
{
    protected static ?string $slug = 'handover-forms/{form}/print';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'assets::print.handover-form';

    public HandoverForm $handoverForm;

    /** Which printing this is: 1 for the original, 2 and on for copies. */
    public int $printNumber = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('assignments.view') && auth()->user()?->hasPermission('assignments.print');
    }

    public function mount(int|string $form): void
    {
        $this->handoverForm = HandoverForm::query()->findOrFail($form);

        abort_unless(auth()->user()?->can('print', $this->handoverForm), 403);

        $this->handoverForm->forceFill([
            'print_count' => $this->handoverForm->print_count + 1,
            'last_printed_at' => now(),
        ])->saveQuietly();

        $this->printNumber = $this->handoverForm->print_count;

        app(AuditLogger::class)->log(
            $this->printNumber === 1 ? 'printed' : 'reprinted',
            'Assets',
            $this->handoverForm,
            [],
            ['copy' => $this->printNumber],
            $this->handoverForm->number,
        );
    }

    public function getLayout(): string
    {
        return 'print.layout';
    }

    public function getTitle(): string
    {
        return $this->handoverForm->number.' · '.$this->handoverForm->title();
    }

    /**
     * Emergency contacts go on the paper only for somebody allowed to see
     * them. For anyone else the form has blank lines to fill in by hand.
     */
    public function showsContacts(): bool
    {
        return auth()->user()?->can('viewSensitive', Employee::class) ?? false;
    }

    public function logoUrl(): ?string
    {
        return app(Branding::class)->logoUrl();
    }

    public function backUrl(): string
    {
        return HandoverFormResource::getUrl('index');
    }
}

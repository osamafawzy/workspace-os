<?php

namespace Modules\Assets\Filament\Admin\Pages;

use App\Support\Audit\AuditLogger;
use App\Support\Branding;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\ReleaseBatchItem;
use Modules\Employees\Models\Employee;

/**
 * The New Data report, as paper.
 *
 * For a draft this is the Quick Print: the rows as they stand, marked as not
 * released, and nothing is recorded. For a released batch it is the Re-Print:
 * what was released, to whom, on which form — counted and audited like the
 * handover forms. Printed with the browser, like them.
 */
class PrintReleaseBatch extends Page
{
    protected static ?string $slug = 'release-new-assets/{batch}/print';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'assets::print.release-batch';

    public ReleaseBatch $releaseBatch;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('releases.view') ?? false;
    }

    public function mount(int|string $batch): void
    {
        $this->releaseBatch = ReleaseBatch::query()->with(['assetType', 'assetModel.manufacturer', 'site', 'location', 'account'])->findOrFail($batch);

        abort_unless(auth()->user()?->can('view', $this->releaseBatch), 403);

        if ($this->releaseBatch->isArchived()) {
            $this->releaseBatch->forceFill(['print_count' => $this->releaseBatch->print_count + 1])->saveQuietly();

            app(AuditLogger::class)->log($this->releaseBatch->print_count === 1 ? 'printed' : 'reprinted', 'Assets', $this->releaseBatch, [], ['copy' => $this->releaseBatch->print_count], $this->releaseBatch->number);
        }
    }

    public function getLayout(): string
    {
        return 'print.layout';
    }

    public function getTitle(): string
    {
        return $this->releaseBatch->number.' · New Data Report';
    }

    /** @return Collection<int, ReleaseBatchItem> */
    public function items(): Collection
    {
        return $this->releaseBatch->items()->with(['handoverForm', 'employee'])->orderBy('id')->get();
    }

    /** @return array<string, string> lower-cased OID => name */
    public function employeeNames(): array
    {
        return Employee::query()
            ->whereIn('oid', $this->releaseBatch->items()->whereNotNull('employee_oid')->pluck('employee_oid'))
            ->get(['oid', 'name'])
            ->mapWithKeys(fn (Employee $employee): array => [mb_strtolower($employee->oid) => $employee->name])
            ->all();
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
        return ReleaseBatchResource::getUrl($this->releaseBatch->isDraft() ? 'edit' : 'view', ['record' => $this->releaseBatch]);
    }
}

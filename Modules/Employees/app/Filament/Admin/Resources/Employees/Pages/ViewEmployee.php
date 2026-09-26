<?php

namespace Modules\Employees\Filament\Admin\Resources\Employees\Pages;

use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Modules\Employees\Filament\Admin\Resources\Employees\EmployeeResource;
use Modules\Employees\Models\Employee;

/** The employee profile. */
class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public const AUDIT_ROUTE = 'filament.admin.resources.audit-logs.index';

    public function getTitle(): string
    {
        /** @var Employee $employee */
        $employee = $this->getRecord();

        return $employee->name;
    }

    public function getSubheading(): ?string
    {
        /** @var Employee $employee */
        $employee = $this->getRecord();

        return collect([$employee->oid, $employee->job_title, $employee->department?->name])->filter()->implode(' · ');
    }

    /** @var list<\Closure(Employee): Action> buttons other modules add */
    protected static array $extraActions = [];

    /**
     * Lets another module put a button on the profile — the Assets module's
     * Assign and Return.
     *
     * @param  \Closure(Employee): Action  $action
     */
    public static function addHeaderAction(\Closure $action): void
    {
        static::$extraActions[] = $action;
    }

    protected function getHeaderActions(): array
    {
        /** @var Employee $employee */
        $employee = $this->getRecord();

        return [
            ...array_map(fn (\Closure $action): Action => $action($employee), static::$extraActions),

            Action::make('history')
                ->label('Full history')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->visible(fn (): bool => Route::has(self::AUDIT_ROUTE) && (auth()->user()?->can('viewAny', AuditLog::class) ?? false))
                ->url(fn (): string => route(self::AUDIT_ROUTE, ['filters' => ['record' => [
                    'type' => $this->getRecord()->getMorphClass(),
                    'id' => $this->getRecord()->getKey(),
                ]]])),

            EditAction::make(),
        ];
    }
}

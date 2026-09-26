<?php

namespace Modules\Assets\Reports;

use App\Support\Reports\ReportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Models\Asset;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Department;

/** Assets with somebody, and since when. */
class AssignedAssetsReport extends AssetReport
{
    public static function key(): string
    {
        return 'assigned-assets';
    }

    public static function label(): string
    {
        return 'Assigned Assets';
    }

    public static function description(): string
    {
        return 'Every asset somebody holds: who, since when, and on which handover form.';
    }

    public function query(): Builder
    {
        return $this->assets()
            ->whereNotNull('employee_id')
            ->with(['assignments' => fn ($query) => $query->whereNull('returned_at')->with('handoverForm')]);
    }

    public function defaultSort(): ?string
    {
        return 'assigned_at:desc';
    }

    public function columns(): array
    {
        $columns = $this->assetColumns();

        return [
            $columns['employee'],
            $columns['oid'],
            ReportColumn::make('department', 'Department', fn (Asset $asset) => $asset->employee?->department?->name),
            $columns['serial'],
            $columns['tag'],
            $columns['type'],
            $columns['model'],
            $columns['status'],
            $columns['site'],
            $columns['assigned_at'],
            ReportColumn::make('form', 'Handover Form', fn (Asset $asset) => $asset->assignments->first()?->handoverForm?->number)->mono(),
        ];
    }

    public function filters(): array
    {
        $filters = $this->assetFilters();

        return [
            SelectFilter::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'name')
                ->getOptionLabelFromRecordUsing(fn (Employee $employee): string => $employee->auditLabel())
                ->searchable(),
            SelectFilter::make('department')
                ->label('Department')
                ->options(fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('employee', fn (Builder $employee) => $employee->where('department_id', $data['value']))
                    : $query),
            $filters['type'],
            $filters['site'],
            Filter::make('assigned_at')
                ->label('Assigned')
                ->schema([DatePicker::make('from')->label('Assigned from'), DatePicker::make('until')->label('Assigned until')])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('assigned_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('assigned_at', '<=', $date))),
        ];
    }
}

<?php

namespace Modules\Assets\Reports;

use App\Models\User;
use App\Support\Reports\ReportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Models\Asset;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Settings\Models\Department;

/**
 * Assets still held by people who have left.
 *
 * "Non-returned" is taken to mean exactly that until the definition behind
 * the legacy DIF MSA screen arrives (plan, gap 3): the employee's status is
 * Left, and the asset is still recorded against them.
 */
class NonReturnedAssetsReport extends AssetReport
{
    public static function key(): string
    {
        return 'non-returned-assets';
    }

    public static function label(): string
    {
        return 'Non-Returned Assets';
    }

    public static function description(): string
    {
        return 'Assets still held by employees who have left, and how long since they went.';
    }

    public function authorize(User $user): bool
    {
        return parent::authorize($user) && $user->hasPermission('employees.view');
    }

    public function query(): Builder
    {
        return $this->assets()
            ->whereHas('employee', fn (Builder $employee) => $employee->where('status', EmployeeStatus::Left));
    }

    public function defaultSort(): ?string
    {
        return 'assigned_at:asc';
    }

    public function columns(): array
    {
        $columns = $this->assetColumns();

        return [
            $columns['employee'],
            $columns['oid'],
            ReportColumn::make('department', 'Department', fn (Asset $asset) => $asset->employee?->department?->name),
            ReportColumn::make('left_at', 'Left', fn (Asset $asset) => $asset->employee?->left_at),
            ReportColumn::make('days', 'Days Since Leaving', fn (Asset $asset) => $asset->employee?->left_at ? (string) (int) $asset->employee->left_at->diffInDays(today()) : null),
            $columns['serial'],
            $columns['tag'],
            $columns['type'],
            $columns['model'],
            $columns['assigned_at'],
            ReportColumn::make('mobile', 'Mobile', fn (Asset $asset) => $asset->employee?->mobile)->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('department')
                ->label('Department')
                ->options(fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('employee', fn (Builder $employee) => $employee->where('department_id', $data['value']))
                    : $query),
            Filter::make('left_at')
                ->label('Left')
                ->schema([DatePicker::make('from')->label('Left from'), DatePicker::make('until')->label('Left until')])
                ->query(fn (Builder $query, array $data): Builder => $query->whereHas('employee', fn (Builder $employee) => $employee
                    ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('left_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('left_at', '<=', $date)))),
            $this->assetFilters()['type'],
        ];
    }
}

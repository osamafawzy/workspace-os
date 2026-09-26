<?php

namespace Modules\Assets\Reports;

use App\Models\User;
use App\Support\Reports\ReportColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Models\Asset;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;

/** One row per employee: what each person holds. */
class EmployeeAssetsReport extends AssetReport
{
    public static function key(): string
    {
        return 'employee-assets';
    }

    public static function label(): string
    {
        return 'Employee Assets';
    }

    public static function description(): string
    {
        return 'One row per employee with how many assets they hold and which.';
    }

    public function authorize(User $user): bool
    {
        return parent::authorize($user) && $user->can('viewAny', Employee::class);
    }

    public function query(): Builder
    {
        return Employee::query()
            ->with(['department', 'site'])
            ->withCount(['assets' => fn (Builder $query) => $query])
            ->with(['assets' => fn ($query) => $query->with(['assetType'])->orderBy('serial_number')]);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        $like = static::like($term);

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->orWhereRaw("lower(oid) like ? escape '!'", [$like])
            ->orWhereHas('assets', fn (Builder $asset) => $asset
                ->whereRaw("lower(serial_number) like ? escape '!'", [$like])
                ->orWhereRaw("lower(asset_tag) like ? escape '!'", [$like])));
    }

    public function searchPlaceholder(): ?string
    {
        return 'Employee name, OID, serial or tag…';
    }

    public function defaultSort(): ?string
    {
        return 'name:asc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('name', 'Employee', fn (Employee $employee) => $employee->name)->sortable('name'),
            ReportColumn::make('oid', 'OID', fn (Employee $employee) => $employee->oid)->mono()->sortable('oid'),
            ReportColumn::make('status', 'Status', fn (Employee $employee) => $employee->status)->badge(),
            ReportColumn::make('department', 'Department', fn (Employee $employee) => $employee->department?->name),
            ReportColumn::make('site', 'Site', fn (Employee $employee) => $employee->site?->name)->hiddenByDefault(),
            ReportColumn::make('count', 'Assets Held', fn (Employee $employee) => (string) $employee->assets_count),
            ReportColumn::make('assets', 'Assets', fn (Employee $employee) => $employee->assets
                ->map(fn (Asset $asset): string => trim(($asset->assetType?->name ?? '').' '.$asset->serial_number))
                ->implode(', ')),
        ];
    }

    public function filters(): array
    {
        return [
            TernaryFilter::make('holding')
                ->label('Holds assets')
                ->default(true)
                ->queries(
                    true: fn (Builder $query): Builder => $query->has('assets'),
                    false: fn (Builder $query): Builder => $query->doesntHave('assets'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            SelectFilter::make('status')->label('Status')->options(EmployeeStatus::class)->multiple(),
            SelectFilter::make('department_id')->label('Department')->relationship('department', 'name')->searchable()->preload(),
            SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),
        ];
    }
}

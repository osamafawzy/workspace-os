<?php

namespace Modules\Assets\Reports;

use App\Models\User;
use App\Support\Reports\ReportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Filament\Admin\Resources\ReturnedAssets\ReturnedAssetResource;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetReturn;
use Modules\Employees\Models\Employee;

/** Every return recorded. */
class ReturnedAssetsReport extends AssetReport
{
    public static function key(): string
    {
        return 'returned-assets';
    }

    public static function label(): string
    {
        return 'Returned Assets';
    }

    public static function description(): string
    {
        return 'Every asset handed back: when, by whom, in what condition, and who received it.';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Asset::class) && $user->can('viewAny', AssetReturn::class);
    }

    public function query(): Builder
    {
        return AssetReturn::query()->with(ReturnedAssetResource::EAGER_LOADS);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        return ReturnedAssetResource::search($query, $term);
    }

    public function searchPlaceholder(): ?string
    {
        return 'Employee, OID, serial, tag, receiver…';
    }

    public function defaultSort(): ?string
    {
        return 'returned_at:desc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('returned_at', 'Returned', fn (AssetReturn $return) => $return->returned_at)->sortable('returned_at'),
            ReportColumn::make('serial', 'Serial Number', fn (AssetReturn $return) => $return->asset?->serial_number)->mono(),
            ReportColumn::make('tag', 'Asset Tag', fn (AssetReturn $return) => $return->asset?->asset_tag)->mono(),
            ReportColumn::make('type', 'Type', fn (AssetReturn $return) => $return->asset?->assetType?->name),
            ReportColumn::make('model', 'Model', fn (AssetReturn $return) => $return->asset?->assetModel?->fullName())->hiddenByDefault(),
            ReportColumn::make('employee', 'Employee', fn (AssetReturn $return) => $return->employee?->name),
            ReportColumn::make('oid', 'OID', fn (AssetReturn $return) => $return->employee?->oid)->mono(),
            ReportColumn::make('condition', 'Condition', fn (AssetReturn $return) => $return->condition)->badge()->sortable('condition'),
            ReportColumn::make('returned_by', 'Brought Back By', fn (AssetReturn $return) => $return->returned_by_name)->hiddenByDefault(),
            ReportColumn::make('received_by', 'Received By', fn (AssetReturn $return) => $return->received_by_name),
            ReportColumn::make('location', 'Put Back At', fn (AssetReturn $return) => collect([$return->site?->name, $return->location?->name])->filter()->implode(' · ')),
            ReportColumn::make('receipt', 'Receipt', fn (AssetReturn $return) => $return->handoverForm?->number)->mono()->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('returned_at')
                ->label('Returned')
                ->schema([DatePicker::make('from')->label('Returned from'), DatePicker::make('until')->label('Returned until')])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('returned_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('returned_at', '<=', $date))),
            SelectFilter::make('condition')->label('Condition')->options(AssetCondition::class)->multiple(),
            SelectFilter::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'name')
                ->getOptionLabelFromRecordUsing(fn (Employee $employee): string => $employee->auditLabel())
                ->searchable(),
            SelectFilter::make('received_by')
                ->label('Received by')
                ->options(fn (): array => AssetReturn::query()->whereNotNull('received_by')->distinct()->orderBy('received_by_name')->pluck('received_by_name', 'received_by')->all()),
            SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),
            SelectFilter::make('location_id')->label('Location')->relationship('location', 'name')->searchable()->preload(),
        ];
    }
}

<?php

namespace Modules\Assets\Reports;

use App\Models\User;
use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;

/**
 * What the asset reports share: the asset's columns, its filters and its
 * search, so "Serial Number" or "Site" means the same in every one of them.
 */
abstract class AssetReport extends Report
{
    public static function group(): string
    {
        return 'Assets';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Asset::class);
    }

    /** @return Builder<Asset> */
    protected function assets(): Builder
    {
        return Asset::query()->with(['assetType', 'assetModel.manufacturer', 'site', 'location', 'account', 'employee.department']);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        return $query->search($term);
    }

    public function searchPlaceholder(): ?string
    {
        return 'Serial, tag, computer, employee, model, location…';
    }

    public function defaultSort(): ?string
    {
        return 'serial_number:asc';
    }

    /** @return array<string, ReportColumn> */
    protected function assetColumns(): array
    {
        return [
            'serial' => ReportColumn::make('serial', 'Serial Number', fn (Asset $asset) => $asset->serial_number)->mono()->sortable('serial_number'),
            'tag' => ReportColumn::make('tag', 'Asset Tag', fn (Asset $asset) => $asset->asset_tag)->mono()->sortable('asset_tag'),
            'type' => ReportColumn::make('type', 'Type', fn (Asset $asset) => $asset->assetType?->name),
            'model' => ReportColumn::make('model', 'Model', fn (Asset $asset) => $asset->assetModel?->fullName()),
            'computer' => ReportColumn::make('computer', 'Computer Name', fn (Asset $asset) => $asset->computer_name)->sortable('computer_name')->hiddenByDefault(),
            'status' => ReportColumn::make('status', 'Status', fn (Asset $asset) => $asset->status)->badge()->sortable('status'),
            'condition' => ReportColumn::make('condition', 'Condition', fn (Asset $asset) => $asset->condition)->sortable('condition'),
            'site' => ReportColumn::make('site', 'Site', fn (Asset $asset) => $asset->site?->name),
            'location' => ReportColumn::make('location', 'Location', fn (Asset $asset) => $asset->location?->name),
            'account' => ReportColumn::make('account', 'Account', fn (Asset $asset) => $asset->account?->name)->hiddenByDefault(),
            'employee' => ReportColumn::make('employee', 'Assigned To', fn (Asset $asset) => $asset->employee?->name),
            'oid' => ReportColumn::make('oid', 'OID', fn (Asset $asset) => $asset->employee?->oid)->mono(),
            'assigned_at' => ReportColumn::make('assigned_at', 'Assigned', fn (Asset $asset) => $asset->assigned_at?->toDateString())->sortable('assigned_at'),
            'purchased' => ReportColumn::make('purchased', 'Purchased', fn (Asset $asset) => $asset->purchase_date)->sortable('purchase_date')->hiddenByDefault(),
            'warranty' => ReportColumn::make('warranty', 'Warranty Until', fn (Asset $asset) => $asset->warranty_expires_at)->sortable('warranty_expires_at')->hiddenByDefault(),
        ];
    }

    /** @return array<string, mixed> */
    protected function assetFilters(): array
    {
        return [
            'status' => SelectFilter::make('status')->label('Status')->options(AssetStatus::class)->multiple(),
            'type' => SelectFilter::make('asset_type_id')->label('Type')->relationship('assetType', 'name')->multiple()->preload(),
            'condition' => SelectFilter::make('condition')->label('Condition')->options(AssetCondition::class)->multiple(),
            'site' => SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),
            'location' => SelectFilter::make('location_id')->label('Location')->relationship('location', 'name')->searchable()->preload(),
            'account' => SelectFilter::make('account_id')->label('Account')->relationship('account', 'name')->searchable()->preload(),
        ];
    }
}

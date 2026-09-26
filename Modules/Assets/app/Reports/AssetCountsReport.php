<?php

namespace Modules\Assets\Reports;

use App\Support\Reports\ReportColumn;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;

/**
 * How many of what, by account and by type.
 *
 * One row per account and asset type, with the totals broken down by status:
 * pick an account, a type, or both, and the report counts what is there —
 * with a grand total under every column, on screen and on paper.
 */
class AssetCountsReport extends AssetReport
{
    public static function key(): string
    {
        return 'asset-counts';
    }

    public static function label(): string
    {
        return 'Asset Counts';
    }

    public static function description(): string
    {
        return 'Counts by account and asset type, broken down by status: how many are held, in stock, in repair, lost or retired.';
    }

    public function query(): Builder
    {
        $count = fn (AssetStatus $status): string => sprintf(
            "sum(case when assets.status = '%s' then 1 else 0 end) as %s_count",
            $status->value,
            $status->value,
        );

        return Asset::query()
            ->with(['account', 'assetType'])
            ->selectRaw(implode(', ', [
                // A key for the row: the table needs one, and the lowest id in
                // the group is as good as any.
                'min(assets.id) as id',
                'assets.account_id',
                'assets.asset_type_id',
                'count(*) as total_count',
                ...array_map($count, AssetStatus::cases()),
                'sum(case when assets.employee_id is not null then 1 else 0 end) as held_count',
            ]))
            ->groupBy('assets.account_id', 'assets.asset_type_id');
    }

    /** Counting rows is not searching for one: the filters do this report's work. */
    public function searchPlaceholder(): ?string
    {
        return null;
    }

    /**
     * Biggest group first. It has to be one of the counts or one of the grouped
     * columns: a grouped query cannot be ordered by anything else.
     */
    public function defaultSort(): ?string
    {
        return 'total_count:desc';
    }

    public function landscape(): bool
    {
        return true;
    }

    public function columns(): array
    {
        $columns = [
            ReportColumn::make('account', 'Account', fn (Asset $row) => $row->account?->name ?? 'No account'),
            ReportColumn::make('type', 'Asset Type', fn (Asset $row) => $row->assetType?->name),
            ReportColumn::make('total', 'Total', fn (Asset $row) => (string) (int) $row->total_count)->sortableByAlias('total_count')->totalled(),
            ReportColumn::make('held', 'With Employees', fn (Asset $row) => (string) (int) $row->held_count)->sortableByAlias('held_count')->totalled(),
        ];

        foreach (AssetStatus::cases() as $status) {
            $alias = $status->value.'_count';

            $columns[] = ReportColumn::make($status->value, $status->getLabel(), fn (Asset $row) => (string) (int) $row->getAttribute($alias))
                ->sortableByAlias($alias)
                ->totalled();
        }

        return $columns;
    }

    public function filters(): array
    {
        $filters = $this->assetFilters();

        // Account and type first: they are what the counts are broken down by,
        // and picking one of each answers "how many of these do we have".
        return [
            $filters['account'],
            $filters['type'],
            $filters['site'],
            $filters['location'],
            $filters['status'],
            $filters['condition'],
        ];
    }
}

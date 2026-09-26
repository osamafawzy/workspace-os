<?php

namespace Modules\Assets\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Enums\AssetStatus;

/** What can be handed out today. */
class AvailableAssetsReport extends AssetReport
{
    public static function key(): string
    {
        return 'available-assets';
    }

    public static function label(): string
    {
        return 'Available Assets';
    }

    public static function description(): string
    {
        return 'Assets in stock or back from somebody, with nobody holding them: what can be handed out today.';
    }

    public function query(): Builder
    {
        return $this->assets()
            ->whereNull('employee_id')
            ->whereIn('status', [AssetStatus::Available, AssetStatus::Returned]);
    }

    public function columns(): array
    {
        $columns = $this->assetColumns();

        return array_values(array_intersect_key($columns, array_flip(['serial', 'tag', 'type', 'model', 'computer', 'status', 'condition', 'site', 'location', 'purchased', 'warranty'])));
    }

    public function filters(): array
    {
        $filters = $this->assetFilters();

        return [$filters['type'], $filters['condition'], $filters['site'], $filters['location'], $filters['account']];
    }
}

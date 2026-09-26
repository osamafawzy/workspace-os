<?php

namespace Modules\Assets\Reports;

use App\Support\Reports\ReportColumn;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Models\Asset;

/** Every headset: asset types marked as headsets. */
class HeadsetReport extends AssetReport
{
    public static function key(): string
    {
        return 'headsets';
    }

    public static function label(): string
    {
        return 'Headsets';
    }

    public static function description(): string
    {
        return 'Every headset, its state, where it is and who has it.';
    }

    public function query(): Builder
    {
        return $this->assets()->headsets();
    }

    public function columns(): array
    {
        $columns = $this->assetColumns();

        return [
            ...array_values(array_intersect_key($columns, array_flip(['serial', 'tag', 'model', 'status', 'condition']))),
            // The cord it came with, which the ops sheet keeps beside it.
            ReportColumn::make('cord_model', 'Cord Model', fn (Asset $asset) => $asset->cord_model),
            ReportColumn::make('cord_serial', 'Cord S/N', fn (Asset $asset) => $asset->cord_serial)->mono()->sortable('cord_serial'),
            ReportColumn::make('cord_condition', 'Cord Status', fn (Asset $asset) => $asset->cord_condition)->badge(),
            ...array_values(array_intersect_key($columns, array_flip(['site', 'location', 'account', 'employee', 'oid', 'purchased']))),
        ];
    }

    public function filters(): array
    {
        $filters = $this->assetFilters();

        return [$filters['status'], $filters['condition'], $filters['site'], $filters['location'], $filters['account']];
    }
}

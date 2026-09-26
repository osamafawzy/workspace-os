<?php

namespace Modules\Assets\Reports;

use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Models\Asset;

/** Every asset in the register. */
class AssetInventoryReport extends AssetReport
{
    public static function key(): string
    {
        return 'asset-inventory';
    }

    public static function label(): string
    {
        return 'Asset Inventory';
    }

    public static function description(): string
    {
        return 'Every asset in the register: what it is, its state, where it is and who has it.';
    }

    public function query(): Builder
    {
        return $this->assets();
    }

    public function columns(): array
    {
        $columns = $this->assetColumns();

        return array_values(array_intersect_key($columns, array_flip(['serial', 'tag', 'type', 'model', 'computer', 'status', 'condition', 'site', 'location', 'account', 'employee', 'purchased', 'warranty'])));
    }

    public function filters(): array
    {
        return [
            ...array_values($this->assetFilters()),
            SelectFilter::make('warranty')
                ->label('Warranty')
                ->options(['expired' => 'Expired', 'expiring' => 'Ends within '.Asset::WARRANTY_WARNING_DAYS.' days', 'none' => 'Not recorded'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'expired' => $query->warrantyExpired(),
                    'expiring' => $query->warrantyExpiring(),
                    'none' => $query->whereNull('warranty_expires_at'),
                    default => $query,
                }),
        ];
    }
}

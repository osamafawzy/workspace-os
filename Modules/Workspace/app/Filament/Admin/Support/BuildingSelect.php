<?php

namespace Modules\Workspace\Filament\Admin\Support;

use Filament\Forms\Components\Select;
use Modules\Workspace\Models\Building;

/**
 * The building field every network record starts with. A single building is
 * picked for you, because there is nothing to choose.
 */
class BuildingSelect
{
    public static function make(): Select
    {
        return Select::make('building_id')
            ->label('Building')
            ->options(fn (): array => Building::query()
                ->with('site')
                ->orderBy('site_id')
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Building $building): array => [$building->getKey() => $building->fullName()])
                ->all())
            ->default(fn (): ?int => Building::query()->count() === 1 ? Building::query()->value('id') : null)
            ->required()
            ->searchable()
            ->live();
    }
}

<?php

namespace Modules\Assets\Filament\Admin\Support;

use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\Asset;
use Modules\Employees\Models\Employee;

/** The "Assigned assets" section on an employee's profile. */
class EmployeeAssetsSection
{
    public static function make(): Section
    {
        return Section::make('Assigned assets')
            ->visible(fn (): bool => auth()->user()?->can('viewAny', Asset::class) ?? false)
            ->schema([
                ViewEntry::make('assigned_assets')
                    ->hiddenLabel()
                    ->view('assets::filament.employee-assets')
                    ->state(fn (Employee $record) => Asset::query()
                        ->with(['assetType', 'assetModel.manufacturer'])
                        ->where('employee_id', $record->getKey())
                        ->orderBy('assigned_at')
                        ->get()
                        ->map(fn (Asset $asset): array => [
                            'label' => collect([$asset->assetType?->name, $asset->assetModel?->fullName()])->filter()->implode(' · '),
                            'serial' => $asset->serial_number,
                            'tag' => $asset->asset_tag,
                            'status' => $asset->status,
                            'since' => $asset->assigned_at?->format('Y-m-d'),
                            'url' => AssetResource::getUrl('view', ['record' => $asset]),
                        ])),
            ]);
    }
}

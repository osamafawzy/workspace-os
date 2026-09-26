<?php

namespace Modules\Assets\Filament\Admin\Resources\Assets\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Modules\Assets\Filament\Admin\Pages\AssignAssets;
use Modules\Assets\Filament\Admin\Pages\ReturnAssets;
use Modules\Assets\Filament\Admin\Pages\UpdateAssets;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\Asset;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return $asset->serial_number;
    }

    public function getSubheading(): ?string
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return collect([$asset->assetType?->name, $asset->assetModel?->fullName(), $asset->asset_tag])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return [
            Action::make('assign')
                ->label('Assign')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => $asset->isAssignable() && Gate::allows('assign-assets'))
                ->url(fn (): string => AssignAssets::getUrl(['asset' => $asset->getKey()])),

            Action::make('return')
                ->label('Return')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->visible(fn (): bool => $asset->employee_id !== null && Gate::allows('return-assets'))
                ->url(fn (): string => ReturnAssets::getUrl(['asset' => $asset->getKey()])),

            Action::make('quickUpdate')
                ->label('Quick update')
                ->icon(Heroicon::OutlinedBolt)
                ->color('gray')
                ->visible(fn (): bool => UpdateAssets::canAccess())
                ->url(fn (): string => UpdateAssets::getUrl(['asset' => $this->getRecord()->getKey()])),

            EditAction::make(),
        ];
    }
}

<?php

namespace Modules\Assets\Filament\Admin\Widgets;

use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\AssetReturn;

/** The latest assets handed back. */
class RecentReturnsWidget extends TableWidget
{
    protected static ?int $sort = 61;

    protected static ?string $heading = 'Recently returned';

    public static function canView(): bool
    {
        return (auth()->user()?->hasPermission('assets.view') ?? false)
            && (auth()->user()?->hasPermission('assignments.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AssetReturn::query()->with(['asset.assetType', 'employee'])->latest('returned_at'))
            ->paginated(false)
            ->defaultKeySort(false)
            ->modifyQueryUsing(fn ($query) => $query->limit(8))
            ->columns([
                TextColumn::make('asset.serial_number')
                    ->label('Asset')
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (AssetReturn $record): ?string => $record->asset?->assetType?->name)
                    ->url(fn (AssetReturn $record): ?string => $record->asset ? AssetResource::getUrl('view', ['record' => $record->asset]) : null),
                TextColumn::make('employee.name')
                    ->label('From')
                    ->description(fn (AssetReturn $record): ?string => $record->employee?->oid),
                TextColumn::make('condition')->label('Condition')->badge(),
                TextColumn::make('returned_at')->label('When')->since(),
            ])
            ->emptyStateHeading('Nothing returned yet');
    }
}

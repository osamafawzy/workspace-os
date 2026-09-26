<?php

namespace Modules\Assets\Filament\Admin\Widgets;

use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\AssetAssignment;

/** The latest assets handed out. */
class RecentAssignmentsWidget extends TableWidget
{
    protected static ?int $sort = 60;

    protected static ?string $heading = 'Recently assigned';

    public static function canView(): bool
    {
        return (auth()->user()?->hasPermission('assets.view') ?? false)
            && (auth()->user()?->hasPermission('assignments.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AssetAssignment::query()->with(['asset.assetType', 'employee', 'handoverForm'])->latest('assigned_at'))
            ->paginated(false)
            ->defaultKeySort(false)
            ->modifyQueryUsing(fn ($query) => $query->limit(8))
            ->columns([
                TextColumn::make('asset.serial_number')
                    ->label('Asset')
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (AssetAssignment $record): ?string => $record->asset?->assetType?->name)
                    ->url(fn (AssetAssignment $record): ?string => $record->asset ? AssetResource::getUrl('view', ['record' => $record->asset]) : null),
                TextColumn::make('employee.name')
                    ->label('To')
                    ->description(fn (AssetAssignment $record): ?string => $record->employee?->oid),
                TextColumn::make('assigned_at')
                    ->label('When')
                    ->since()
                    ->description(fn (AssetAssignment $record): ?string => $record->returned_at ? 'Returned since' : $record->handoverForm?->number),
            ])
            ->emptyStateHeading('Nothing assigned yet');
    }
}

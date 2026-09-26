<?php

namespace Modules\Assets\Filament\Admin\Resources\UpdateReasons;

use Filament\Tables\Columns\TextColumn;
use Modules\Assets\Filament\Admin\Resources\UpdateReasons\Pages\ManageUpdateReasons;
use Modules\Assets\Models\UpdateReason;
use Modules\Settings\Filament\Admin\Support\LookupResource;

/**
 * Settings → Update Reasons: why an asset was changed or replaced.
 *
 * The list the Update Assets screen offers. Add to it, rename it, or switch a
 * reason off when it stops being used — the entries already recorded keep the
 * wording they were given.
 */
class UpdateReasonResource extends LookupResource
{
    protected static ?string $model = UpdateReason::class;

    protected static string $navigationKey = 'update-reasons';

    protected static ?string $modelLabel = 'update reason';

    protected static function extraColumns(): array
    {
        return [
            TextColumn::make('history_count')->label('Times used')->counts('history')->sortable(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUpdateReasons::route('/'),
        ];
    }
}

<?php

namespace Modules\Audit\Filament\Admin\Widgets;

use App\Models\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** What people have been doing: the latest audit entries. */
class RecentActivityWidget extends TableWidget
{
    protected static ?int $sort = 70;

    protected static ?string $heading = 'Recent activity';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', AuditLog::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AuditLog::query()->latest('id'))
            ->paginated(false)
            ->defaultKeySort(false)
            ->modifyQueryUsing(fn ($query) => $query->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->tooltip(fn (AuditLog $record): string => $record->created_at->format('Y-m-d H:i:s')),
                TextColumn::make('user_name')->label('Who')->placeholder('System'),
                TextColumn::make('action')->label('Did')->badge()->color('gray'),
                TextColumn::make('record_label')->label('To')->placeholder('-')->description(fn (AuditLog $record): string => $record->module),
            ])
            ->emptyStateHeading('Nothing recorded yet');
    }
}

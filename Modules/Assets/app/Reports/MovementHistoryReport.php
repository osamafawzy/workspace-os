<?php

namespace Modules\Assets\Reports;

use App\Support\Reports\ReportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetHistory;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\UpdateReason;

/** Everything that happened to assets, one event per row. */
class MovementHistoryReport extends AssetReport
{
    /** The events worth filtering by, as the history records them. */
    public const EVENTS = ['registered', 'assigned', 'reassigned', 'unassigned', 'returned', 'moved', 'status changed', 'relabelled', 'updated'];

    public static function key(): string
    {
        return 'movement-history';
    }

    public static function label(): string
    {
        return 'Movement History';
    }

    public static function description(): string
    {
        return 'Every change to every asset: assigned, returned, moved, relabelled — before and after, by whom and when.';
    }

    public function query(): Builder
    {
        return AssetHistory::query()->with(['asset.assetType']);
    }

    public function search(Builder $query, string $term): ?Builder
    {
        $like = static::like($term);

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(user_name) like ? escape '!'", [$like])
            ->orWhereRaw("lower(changes) like ? escape '!'", [$like])
            ->orWhereHas('asset', fn (Builder $asset) => $asset
                ->whereRaw("lower(serial_number) like ? escape '!'", [$like])
                ->orWhereRaw("lower(asset_tag) like ? escape '!'", [$like])));
    }

    public function searchPlaceholder(): ?string
    {
        return 'Serial, tag, a name or place in the change, user…';
    }

    public function defaultSort(): ?string
    {
        return 'id:desc';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('at', 'When', fn (AssetHistory $entry) => $entry->created_at?->format('Y-m-d H:i'))->sortable('id'),
            ReportColumn::make('serial', 'Serial Number', fn (AssetHistory $entry) => $entry->asset?->serial_number)->mono(),
            ReportColumn::make('type', 'Type', fn (AssetHistory $entry) => $entry->asset?->assetType?->name),
            ReportColumn::make('event', 'Event', fn (AssetHistory $entry) => ucfirst($entry->event))->badge()->sortable('event'),
            ReportColumn::make('changes', 'Changes', fn (AssetHistory $entry) => collect($entry->changes ?? [])
                ->map(fn (array $change, string $field): string => (Asset::TRACKED[$field] ?? $field).': '
                    .($entry->event === 'registered' ? ($change['to'] ?? '—') : ($change['from'] ?? '—').' → '.($change['to'] ?? '—')))
                ->implode('; ')),
            ReportColumn::make('reason', 'Reason', fn (AssetHistory $entry) => $entry->reason)->badge(),
            ReportColumn::make('by', 'By', fn (AssetHistory $entry) => $entry->user_name ?? 'System')->sortable('user_name'),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('event')->label('Event')->options(array_combine(self::EVENTS, array_map('ucfirst', self::EVENTS)))->multiple(),
            Filter::make('created_at')
                ->label('When')
                ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            SelectFilter::make('asset_type')
                ->label('Type')
                ->options(fn (): array => AssetType::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->whereHas('asset', fn (Builder $asset) => $asset->where('asset_type_id', $data['value']))
                    : $query),
            SelectFilter::make('asset_update_reason_id')
                ->label('Reason')
                ->options(fn (): array => UpdateReason::query()->ordered()->pluck('name', 'id')->all())
                ->multiple(),
            SelectFilter::make('user_id')
                ->label('By')
                ->options(fn (): array => AssetHistory::query()->whereNotNull('user_id')->distinct()->orderBy('user_name')->pluck('user_name', 'user_id')->all()),
        ];
    }

    public function landscape(): bool
    {
        return true;
    }
}

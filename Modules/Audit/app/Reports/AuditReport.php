<?php

namespace Modules\Audit\Reports;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Reports\Report;
use App\Support\Reports\ReportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audit log as a report: who did what, when, from where.
 *
 * Personal data never reaches the log (it is redacted where it is written),
 * so the report needs no hiding of its own.
 */
class AuditReport extends Report
{
    public static function key(): string
    {
        return 'audit';
    }

    public static function label(): string
    {
        return 'Audit Trail';
    }

    public static function description(): string
    {
        return 'Every recorded change: who made it, when, from which address, to which record, and what changed.';
    }

    public static function group(): string
    {
        return 'Admin';
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', AuditLog::class);
    }

    public function query(): Builder
    {
        return AuditLog::query();
    }

    public function search(Builder $query, string $term): ?Builder
    {
        $like = static::like($term);

        return $query->where(fn (Builder $query) => $query
            ->whereRaw("lower(user_name) like ? escape '!'", [$like])
            ->orWhereRaw("lower(record_label) like ? escape '!'", [$like])
            ->orWhereRaw("lower(action) like ? escape '!'", [$like])
            ->orWhereRaw("lower(ip_address) like ? escape '!'", [$like]));
    }

    public function searchPlaceholder(): ?string
    {
        return 'User, record, action, IP…';
    }

    public function defaultSort(): ?string
    {
        return 'id:desc';
    }

    public function landscape(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('when', 'When', fn (AuditLog $log) => $log->created_at?->format('Y-m-d H:i:s'))->sortable('id'),
            ReportColumn::make('user', 'User', fn (AuditLog $log) => $log->user_name ?? 'System')->sortable('user_name'),
            ReportColumn::make('action', 'Action', fn (AuditLog $log) => $log->action)->badge()->sortable('action'),
            ReportColumn::make('module', 'Module', fn (AuditLog $log) => $log->module)->sortable('module'),
            ReportColumn::make('record', 'Record', fn (AuditLog $log) => $log->record_label),
            ReportColumn::make('changes', 'Changes', fn (AuditLog $log) => collect($log->new_values ?? [])
                ->map(function (mixed $value, string $field) use ($log): string {
                    $old = $log->old_values[$field] ?? null;
                    $show = fn (mixed $v): string => $v === null ? '—' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));

                    return str_replace('_', ' ', $field).': '.(array_key_exists($field, $log->old_values ?? []) ? $show($old).' → ' : '').$show($value);
                })
                ->implode('; '))->hiddenByDefault(),
            ReportColumn::make('ip', 'IP', fn (AuditLog $log) => $log->ip_address)->mono(),
            ReportColumn::make('host', 'Host', fn (AuditLog $log) => $log->hostname)->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('module')->options(fn (): array => AuditLog::query()->distinct()->orderBy('module')->pluck('module', 'module')->all()),
            SelectFilter::make('action')->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all())->multiple(),
            SelectFilter::make('user_id')->label('User')->relationship('user', 'name')->searchable()->preload(),
            Filter::make('created_at')
                ->label('Date')
                ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
        ];
    }
}

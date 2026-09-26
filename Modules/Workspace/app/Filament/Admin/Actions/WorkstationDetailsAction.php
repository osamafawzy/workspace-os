<?php

namespace Modules\Workspace\Filament\Admin\Actions;

use App\Models\AuditLog;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Search\WorkstationSearch;

/**
 * One desk's whole record, read-only, in a panel that slides in from the side:
 * where it is, how it is patched, what is on it, and what has happened to it —
 * with the ways on from there: find it on the map, edit it, its full history.
 *
 * Works on a table row (the row is the record) and on a page that is not a
 * table, mounted with `mountAction('details', ['workstation' => $id])`. An id
 * from the browser is checked against the policy like any other request.
 */
class WorkstationDetailsAction
{
    /** How many audit entries the panel lists before pointing at the full log. */
    public const HISTORY = 8;

    /** The audit log's list route. The Audit module may be switched off. */
    public const AUDIT_ROUTE = 'filament.admin.resources.audit-logs.index';

    public static function make(string $name = 'details'): Action
    {
        return Action::make($name)
            ->label('Details')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalHeading(fn (mixed $record, array $arguments): string => static::desk($record, $arguments)->name)
            ->modalDescription(fn (mixed $record, array $arguments): ?string => static::desk($record, $arguments)->floor?->fullName())
            ->modalContent(function (mixed $record, array $arguments) {
                $desk = static::desk($record, $arguments);

                return view('workspace::filament.workstation-details', [
                    'desk' => $desk,
                    'history' => static::canSeeHistory() ? static::history($desk) : null,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(fn (mixed $record, array $arguments): array => static::footer(static::desk($record, $arguments)));
    }

    /**
     * The desk the panel is about, with everything it shows loaded. Refused
     * unless this user may see desks: the id can come straight from a browser.
     */
    public static function desk(mixed $record, array $arguments): Workstation
    {
        $desk = $record instanceof Workstation
            ? $record->loadMissing(WorkstationSearch::EAGER_LOADS)
            : Workstation::query()->with(WorkstationSearch::EAGER_LOADS)->findOrFail((int) ($arguments['workstation'] ?? 0));

        abort_unless(auth()->user()?->can('view', $desk), 403);

        return $desk;
    }

    /** @return array<int, Action> */
    protected static function footer(Workstation $desk): array
    {
        $placed = $desk->mapObject !== null;
        $user = auth()->user();
        $links = static::$assetLinks ? (static::$assetLinks)($desk) : [];

        return [
            Action::make('locate')
                ->label('Locate on map')
                ->icon(Heroicon::OutlinedMapPin)
                ->url(static::locateUrl($desk))
                ->disabled(! $placed)
                ->tooltip($placed ? null : 'This desk is not on its floor\'s map yet')
                ->visible(fn (): bool => $desk->floor !== null && ($user?->can('view', $desk->floor) ?? false)),

            Action::make('edit')
                ->label('Edit')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->url(fn (): string => WorkstationResource::getUrl('edit', ['record' => $desk]))
                ->visible(fn (): bool => $user?->can('update', $desk) ?? false),

            Action::make('history')
                ->label('View history')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(fn (): ?string => static::historyUrl($desk))
                ->visible(fn (): bool => static::canSeeHistory()),

            // The PC's asset record, found by its serial number. The monitor
            // gets its own button only when there is one to go to.
            Action::make('asset')
                ->label('View asset')
                ->icon(Heroicon::OutlinedComputerDesktop)
                ->color('gray')
                ->url($links['pc'] ?? null)
                ->disabled(! isset($links['pc']))
                ->tooltip(isset($links['pc']) ? null : (static::$assetLinks
                    ? 'No asset is recorded with this desk\'s PC serial number'
                    : 'Assets are not recorded yet')),

            Action::make('monitorAsset')
                ->label('View monitor')
                ->icon(Heroicon::OutlinedTv)
                ->color('gray')
                ->url($links['monitor'] ?? null)
                ->visible(isset($links['monitor'])),
        ];
    }

    /** @var (Closure(Workstation): array{pc?: string, monitor?: string})|null */
    protected static ?Closure $assetLinks = null;

    /**
     * Lets the Assets module say where a desk's PC and monitor are in the
     * asset register: links to them, keyed "pc" and "monitor", for whichever
     * exist and the user may open.
     *
     * @param  Closure(Workstation): array{pc?: string, monitor?: string}  $links
     */
    public static function linkAssetsUsing(?Closure $links): void
    {
        static::$assetLinks = $links;
    }

    /** The desk's floor map, which flies to it and lights it up. */
    public static function locateUrl(Workstation $desk): string
    {
        return FloorResource::getUrl('plan', ['record' => $desk->floor_id, 'locate' => $desk->name]);
    }

    /** The audit log, narrowed to this desk. */
    public static function historyUrl(Workstation $desk): ?string
    {
        return static::historyUrlFor($desk->getKey());
    }

    /** The same, for a desk id not loaded — or a placeholder the browser fills in. */
    public static function historyUrlFor(int|string $deskId): ?string
    {
        if (! Route::has(self::AUDIT_ROUTE)) {
            return null;
        }

        return route(self::AUDIT_ROUTE, ['filters' => ['record' => [
            'type' => (new Workstation)->getMorphClass(),
            'id' => $deskId,
        ]]]);
    }

    public static function canSeeHistory(): bool
    {
        return Route::has(self::AUDIT_ROUTE) && (auth()->user()?->can('viewAny', AuditLog::class) ?? false);
    }

    /** @return Collection<int, AuditLog> */
    public static function history(Workstation $desk): Collection
    {
        return AuditLog::query()
            ->where('auditable_type', $desk->getMorphClass())
            ->where('auditable_id', $desk->getKey())
            ->latest('id')
            ->limit(self::HISTORY)
            ->get();
    }
}

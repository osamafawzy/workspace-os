<?php

namespace Modules\Workspace\Filament\Admin\Resources\Floors\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Filament\Admin\Actions\AddManyWorkstations;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;
use Modules\Workspace\Filament\Admin\Schemas\WorkstationDetailFields;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

/**
 * The floor, drawn.
 *
 * Every desk on the floor is a pin at a percentage coordinate, dragged into
 * the spot it occupies in the real room. The browser owns the interaction —
 * drag, zoom, pan all happen in Alpine without a round trip — and this class
 * is only ever asked to write a settled coordinate back.
 *
 * Percentages, not pixels: the plan drawings are not here yet and will not all
 * be the same size when they are, so a placement made against today's blank
 * grid still lands in the same relative spot once a real plan is behind it.
 */
class FloorPlan extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FloorResource::class;

    protected string $view = 'workspace::filament.pages.floor-plan';

    protected static ?string $title = 'Plan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * Looking at a plan needs only "view floors". Changing it is checked on
     * every write below, not just by hiding buttons: the plan calls these
     * methods straight from the browser, so a hidden button is not a guard.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Floor
            ? (auth()->user()?->can('view', $record) ?? false)
            : FloorResource::canViewAny();
    }

    /** Whether this user may move desks around this floor's plan. */
    public function canArrange(): bool
    {
        return auth()->user()?->can('arrange', $this->getRecord()) ?? false;
    }

    protected function authorizeArranging(): void
    {
        abort_unless($this->canArrange(), 403);
    }

    public function getTitle(): string
    {
        return $this->getRecord()->name.' · Plan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Plan';
    }

    /**
     * Adding desks from the plan itself, because looking at a half-empty floor
     * is exactly when you notice you need another sixty of them.
     */
    protected function getHeaderActions(): array
    {
        return [
            AddManyWorkstations::make(
                fn () => $this->getRecord(),
                // The plan is a client-side component that will not know about
                // rows created behind its back, so the page is reloaded rather
                // than left showing a stale floor.
                fn () => $this->redirect(static::getUrl(['record' => $this->getRecord()])),
            ),
            $this->matchToDrawing(),
        ];
    }

    /**
     * Set the floor's real size from its drawing.
     *
     * A drawing has a shape but no scale — 1088 x 778 pixels could be a meeting
     * room or a city block — so this asks for the one measurement somebody
     * actually knows, how wide the floor is, and takes the depth from the
     * drawing rather than making them measure it twice.
     *
     * Worth having rather than leaving the two to disagree: the snap step is in
     * metres, the capacity warning is in metres, the default grid for a filled
     * area is in metres, and the slab in the 3D building is in metres. A floor
     * recorded as 24 x 16 with a full floor plate drawn behind it is wrong in
     * every one of those places at once.
     */
    protected function matchToDrawing(): Action
    {
        return Action::make('matchToDrawing')
            ->label('Set size from drawing')
            ->icon(Heroicon::OutlinedArrowsPointingOut)
            ->color('gray')
            ->authorize(fn (): bool => $this->canArrange())
            ->visible(fn (): bool => $this->getRecord()->planAspectRatio() !== null)
            ->modalHeading('Set the floor size from its drawing')
            ->modalDescription(fn (): string => 'The drawing is '
                .number_format((float) $this->getRecord()->planAspectRatio(), 3)
                .' times wider than it is deep. Give the real width and the depth follows from it.')
            ->modalSubmitActionLabel('Set size')
            ->fillForm(fn (): array => ['width_m' => $this->getRecord()->width_m])
            ->schema([
                TextInput::make('width_m')
                    ->label('Width')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(500)
                    ->suffix('m')
                    ->live(onBlur: true)
                    ->helperText(function (Get $get): string {
                        $ratio = $this->getRecord()->planAspectRatio();
                        $width = (float) $get('width_m');

                        if ($ratio === null || $width <= 0) {
                            return 'How wide this floor is, wall to wall.';
                        }

                        return 'The depth becomes '.number_format($width / $ratio, 2).' m.';
                    }),
            ])
            ->action(function (array $data): void {
                $floor = $this->getRecord();
                $ratio = $floor->planAspectRatio();

                if ($ratio === null) {
                    return;
                }

                $width = round((float) $data['width_m'], 2);
                $depth = round($width / $ratio, 2);

                $floor->update(['width_m' => $width, 'depth_m' => $depth]);

                Notification::make()
                    ->title('This floor is now '.$width.' x '.$depth.' m')
                    ->success()
                    ->send();

                // The snap step and the capacity are handed to the plan at
                // mount and are in metres, so both are now stale.
                $this->redirect(static::getUrl(['record' => $floor]));
            });
    }

    /**
     * The desks handed to the browser. `x`/`y` are null for a desk that exists
     * but has not been put anywhere yet — those are the ones in the tray.
     *
     * @return array<int, array{id: int, name: string, x: float|null, y: float|null}>
     */
    public function planDesks(): array
    {
        return $this->getRecord()
            ->workstations()
            ->orderBy('name')
            ->get()
            ->map(fn (Workstation $desk): array => [
                'id' => $desk->getKey(),
                'name' => $desk->name,
                'x' => $desk->position_x,
                'y' => $desk->position_y,
                // Just whether there is anything recorded, not what — the plan
                // marks those pins so "which desks have we not done yet" is
                // answerable by looking, and the values themselves stay in the
                // modal where they were entered.
                'details' => $desk->hasDetails(),
            ])
            ->all();
    }

    /** The plan drawing behind the pins, or null while there is not one. */
    public function planImageUrl(): ?string
    {
        $floor = $this->getRecord();

        return $floor->hasPlan()
            ? Storage::disk('public')->url($floor->plan_path)
            : null;
    }

    public function place(int $workstation, float $x, float $y): void
    {
        $this->authorizeArranging();

        $this->deskOnThisFloor($workstation)->update([
            'position_x' => $this->clamp($x),
            'position_y' => $this->clamp($y),
        ]);
    }

    public function unplace(int $workstation): void
    {
        $this->authorizeArranging();

        $this->deskOnThisFloor($workstation)->update([
            'position_x' => null,
            'position_y' => null,
        ]);
    }

    /**
     * The modal behind a click on a pin.
     *
     * Mounted from the browser with `$wire.mountAction('deskDetails', {...})`
     * rather than rendered as a button, because the thing you click is a pin on
     * a canvas-like surface, not a row with an actions column.
     *
     * The desk id arrives from the browser, so it is resolved through this
     * floor's own relationship — the same guard as every other write here.
     */
    public function deskDetailsAction(): Action
    {
        return Action::make('deskDetails')
            ->modalHeading(fn (array $arguments): string => $this->deskOnThisFloor((int) $arguments['workstation'])->name)
            ->modalDescription('Every field is optional. Anything not traced yet can stay empty.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Save')
            // Somebody who may look but not edit still gets the details —
            // read-only, with no Save button to press.
            ->disabledSchema(fn (array $arguments): bool => ! $this->canUpdateDesk((int) $arguments['workstation']))
            ->modalSubmitAction(fn (array $arguments): ?bool => $this->canUpdateDesk((int) $arguments['workstation']) ? null : false)
            ->fillForm(fn (array $arguments): array => $this
                ->deskOnThisFloor((int) $arguments['workstation'])
                ->only(Workstation::DETAIL_COLUMNS))
            ->schema(WorkstationDetailFields::make())
            ->action(function (array $arguments, array $data): void {
                abort_unless($this->canUpdateDesk((int) $arguments['workstation']), 403);

                // Only the detail columns, taken by name. $data is shaped
                // by the schema, but writing it straight through would mean a
                // field added to that schema for display could reach update().
                $this->deskOnThisFloor((int) $arguments['workstation'])->update(
                    collect(Workstation::DETAIL_COLUMNS)
                        ->mapWithKeys(fn (string $column): array => [$column => $data[$column] ?? null])
                        ->all()
                );

                Notification::make()->title('Saved')->success()->send();

                // The plan is a client-side component sitting behind wire:ignore
                // and will not see this write, so it is told which desk changed
                // and whether it now has anything on it. Reloading the whole
                // page to update one dot would throw away the zoom and pan.
                $this->dispatch('desk-details-saved', workstation: (int) $arguments['workstation'], hasDetails: $this->deskOnThisFloor((int) $arguments['workstation'])->hasDetails());
            });
    }

    /**
     * Fill a rectangle drawn on the plan with desks from the tray.
     *
     * The box arrives from the browser as four percentages, so it is ordered
     * and clamped here rather than trusted — nothing stops a crafted call
     * asking for a box running from 400% to -50%.
     *
     * @return array<int, array{id: int, name: string, x: float|null, y: float|null}>
     */
    public function fillArea(float $x1, float $y1, float $x2, float $y2, int $columns, int $rows): array
    {
        $this->authorizeArranging();

        $box = [
            $this->clamp(min($x1, $x2)),
            $this->clamp(min($y1, $y2)),
            $this->clamp(max($x1, $x2)),
            $this->clamp(max($y1, $y2)),
        ];

        // A grid has to be a grid, and it has to be a finite one: a crafted
        // 10000 by 10000 would build a hundred million positions on its way to
        // finding out the tray only had eight desks in it.
        $columns = max(1, min(100, $columns));
        $rows = max(1, min(100, $rows));

        $placed = app(ArrangeWorkstations::class)->fill($this->getRecord(), $box, $columns, $rows);
        $asked = $columns * $rows;

        if ($placed->isEmpty()) {
            Notification::make()
                ->title('No desks left to place')
                ->body('Every desk on this floor is already on the plan. Use Add many to create more.')
                ->warning()
                ->send();
        } elseif ($placed->count() < $asked) {
            Notification::make()
                ->title('Placed '.$placed->count().' of the '.$asked.' you asked for')
                ->body('That is every desk still in the tray. Use Add many to create the other '.($asked - $placed->count()).'.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Placed '.$placed->count().' desks')
                ->body($placed->first()->name.' to '.$placed->last()->name)
                ->success()
                ->send();
        }

        return $this->planDesks();
    }

    /**
     * Lay every unplaced desk out, leaving already-placed desks where they are.
     *
     * The arranging itself lives in an action because the bulk-add flow does
     * the same thing the moment it has created the desks.
     *
     * @return array<int, array{id: int, name: string, x: float|null, y: float|null}>
     */
    public function autoArrange(): array
    {
        $this->authorizeArranging();

        app(ArrangeWorkstations::class)->handle($this->getRecord());

        return $this->planDesks();
    }

    /**
     * Take every desk off the plan. The desks themselves are untouched — this
     * clears where they sit, not that they exist.
     *
     * @return array<int, array{id: int, name: string, x: float|null, y: float|null}>
     */
    public function clearPlacements(): array
    {
        $this->authorizeArranging();

        $this->getRecord()->workstations()->update([
            'position_x' => null,
            'position_y' => null,
        ]);

        return $this->planDesks();
    }

    /**
     * Scoped through the floor's own relationship, so a crafted id cannot move
     * a desk that belongs to a different floor.
     */
    protected function deskOnThisFloor(int $workstation): Workstation
    {
        /** @var Floor $floor */
        $floor = $this->getRecord();

        return $floor->workstations()->findOrFail($workstation);
    }

    protected function canUpdateDesk(int $workstation): bool
    {
        return auth()->user()?->can('update', $this->deskOnThisFloor($workstation)) ?? false;
    }

    protected function clamp(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }
}

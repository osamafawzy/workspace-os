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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Modules\Workspace\Actions\SaveFloorMap;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Actions\AddManyWorkstations;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Filament\Admin\Pages\SearchWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Filament\Admin\Schemas\WorkstationDetailFields;
use Modules\Workspace\FloorMap\FloorMapConflict;
use Modules\Workspace\FloorMap\FloorObjectTypes;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Modules\Workspace\Search\WorkstationSearch;

/**
 * The floor, mapped.
 *
 * The editor itself runs in the browser (floor-map.js): the map is a draft
 * there, every change is undoable, and nothing is written until Save. This
 * page hands it the floor and its objects, and is the only way anything the
 * editor did reaches the database — through {@see saveMap()} and
 * {@see createDesk()}, both checked on the server whatever the browser shows.
 */
class FloorPlan extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FloorResource::class;

    protected string $view = 'workspace::filament.pages.floor-plan';

    protected static ?string $title = 'Map';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    /**
     * A desk to fly to and light up when the map opens: its Workstation ID
     * (or anything else that names exactly one desk on this floor). This is
     * what "Locate on map" links to.
     */
    #[Url]
    public ?string $locate = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * Looking at a map needs only "view floors". Changing it is checked on
     * every write, not just by hiding buttons: the editor calls these methods
     * straight from the browser, so a hidden button is not a guard.
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

    /** Whether this user may change this floor's map. */
    public function canArrange(): bool
    {
        return auth()->user()?->can('arrange', $this->getRecord()) ?? false;
    }

    public function canCreateDesks(): bool
    {
        return $this->canArrange() && (auth()->user()?->can('create', Workstation::class) ?? false);
    }

    public function getTitle(): string
    {
        return $this->getRecord()->name.' · Map';
    }

    public static function getNavigationLabel(): string
    {
        return 'Map';
    }

    /**
     * Adding desks from the map itself, because looking at a half-empty floor
     * is exactly when you notice you need another sixty of them.
     */
    protected function getHeaderActions(): array
    {
        return [
            AddManyWorkstations::make(
                fn () => $this->getRecord(),
                // The editor is a client-side draft that does not know about
                // rows created behind its back, so the page reloads.
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
     * drawing rather than making them measure it twice. The map scales with it
     * (see ScaleFloorMap), so nothing already placed moves off its spot on the
     * drawing.
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
                .' times wider than it is deep. Give the real width and the depth follows from it. Unsaved changes on the map are lost.')
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

                $this->redirect(static::getUrl(['record' => $floor]));
            });
    }

    /**
     * Everything the editor starts from.
     *
     * @return array<string, mixed>
     */
    public function mapConfig(): array
    {
        /** @var Floor $floor */
        $floor = $this->getRecord()->loadMissing('building');

        $desks = $floor->workstations()->with('switchPort.networkSwitch')->orderBy('name')->get();
        $locate = filled($this->locate) ? $this->deskToLocate((string) $this->locate) : null;
        $canSearch = WorkstationResource::canViewAny();

        return [
            'floor' => [
                'id' => $floor->getKey(),
                'name' => $floor->name,
                'width' => $floor->width_m,
                'depth' => $floor->depth_m,
                'planUrl' => $this->planImageUrl(),
            ],
            'revision' => $floor->map_revision,
            'canArrange' => $this->canArrange(),
            'canCreateDesks' => $this->canCreateDesks(),
            'types' => app(FloorObjectTypes::class)->toClient(),
            'objects' => $floor->mapObjects()->orderBy('id')->get()->map->toClient()->all(),
            'desks' => $desks->mapWithKeys(fn (Workstation $desk): array => [$desk->getKey() => self::deskForMap($desk)])->all(),
            'locate' => filled($this->locate) ? [
                'term' => mb_substr((string) $this->locate, 0, 100),
                'desk' => $locate?->getKey(),
            ] : null,
            'canSearch' => $canSearch,
            'searchUrl' => $canSearch ? SearchWorkstation::getUrl() : null,
            // The browser puts each desk's id in place of __DESK__.
            'historyUrl' => WorkstationDetailsAction::canSeeHistory()
                ? WorkstationDetailsAction::historyUrlFor('__DESK__')
                : null,
            'floors' => Floor::query()
                ->with('building')
                ->when($floor->building_id, fn ($query) => $query->orderByRaw('building_id = ? desc', [$floor->building_id]))
                ->orderBy('building_id')
                ->inBuildingOrder()
                ->get()
                ->map(fn (Floor $other): array => [
                    'id' => $other->getKey(),
                    'label' => $other->fullName(),
                    'url' => static::getUrl(['record' => $other]),
                    'current' => $other->is($floor),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> one desk as the map shows it */
    public static function deskForMap(Workstation $desk): array
    {
        $status = $desk->status ?? WorkstationStatus::Active;

        return [
            'id' => $desk->getKey(),
            'name' => $desk->name,
            'number' => $desk->workstation_number,
            'computer' => $desk->computer_name,
            'port' => $desk->switchPort?->label(),
            'ip' => $desk->ip_address,
            'status' => $status->value,
            'statusLabel' => $status->getLabel(),
            'statusColor' => $status->mapColor(),
            'details' => $desk->hasDetails(),
        ];
    }

    /**
     * The desk a locate link means, on this floor: its Workstation ID, its
     * database id, or anything else that names exactly one desk here.
     */
    protected function deskToLocate(string $term): ?Workstation
    {
        /** @var Floor $floor */
        $floor = $this->getRecord();
        $term = trim(mb_substr($term, 0, 200));

        return $floor->workstations()->whereRaw('lower(name) = ?', [mb_strtolower($term)])->first()
            ?? (ctype_digit($term) ? $floor->workstations()->find((int) $term) : null)
            ?? app(WorkstationSearch::class)->locate($term, $floor);
    }

    /**
     * The map's find box came up empty on this floor: is the desk on another?
     *
     * Answers with where to go rather than going, so the editor can refuse to
     * leave a draft with unsaved changes behind.
     *
     * @return array{found: bool, message: string, url?: string}
     */
    public function locateElsewhere(string $term): array
    {
        abort_unless(WorkstationResource::canViewAny(), 403);

        $term = trim(mb_substr($term, 0, 200));
        $search = app(WorkstationSearch::class);
        $desk = $term === '' ? null : $search->locate($term);

        if (! $desk && $term !== '') {
            $matches = $search->search($term, 2);

            if ($matches->count() > 1) {
                return [
                    'found' => false,
                    'message' => "More than one workstation matches “{$term}”.",
                    'url' => SearchWorkstation::getUrl(['q' => $term]),
                ];
            }

            $desk = $matches->first();
        }

        if (! $desk) {
            return ['found' => false, 'message' => "No workstation matches “{$term}”."];
        }

        if ($desk->floor_id === $this->getRecord()->getKey()) {
            return ['found' => false, 'message' => "{$desk->name} is on this floor but not on its map yet."];
        }

        if (! $desk->mapObject || ! $desk->floor || ! (auth()->user()?->can('view', $desk->floor) ?? false)) {
            return ['found' => false, 'message' => "{$desk->name} is on {$desk->floor?->name} but not on its map yet."];
        }

        return [
            'found' => true,
            'message' => "{$desk->name} is on {$desk->floor->fullName()}.",
            'url' => WorkstationDetailsAction::locateUrl($desk),
        ];
    }

    /** The drawing behind the map, or null while there is not one. */
    public function planImageUrl(): ?string
    {
        $floor = $this->getRecord();

        return $floor->hasPlan()
            ? Storage::disk('public')->url($floor->plan_path)
            : null;
    }

    /**
     * Save the editor's draft: the whole map.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, mixed>
     */
    public function saveMap(int $revision, array $objects): array
    {
        abort_unless($this->canArrange(), 403);

        try {
            $result = app(SaveFloorMap::class)->handle($this->getRecord(), $revision, $objects);
        } catch (FloorMapConflict) {
            Notification::make()
                ->title('Somebody else has changed this map')
                ->body('Reload to see their changes. What you have not saved here cannot be kept.')
                ->danger()
                ->persistent()
                ->send();

            return ['ok' => false, 'reason' => 'conflict'];
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('The map was not saved')
                ->body(collect($exception->errors())->flatten()->take(5)->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            return ['ok' => false, 'reason' => 'invalid', 'errors' => $exception->errors()];
        }

        $summary = $result['summary'];

        Notification::make()
            ->title('Map saved')
            ->body(array_sum($summary) > 0
                ? "{$summary['created']} added, {$summary['updated']} changed, {$summary['deleted']} removed."
                : 'Nothing had changed.')
            ->success()
            ->send();

        return ['ok' => true, ...$result];
    }

    /**
     * A new desk, made from the map when there is none left in the tray.
     *
     * @return array<string, mixed>
     */
    public function createDesk(string $name): array
    {
        abort_unless($this->canCreateDesks(), 403);

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'message' => 'A Workstation ID is 1 to 100 characters.'];
        }

        /** @var Floor $floor */
        $floor = $this->getRecord();

        if ($floor->workstations()->where('name', $name)->exists()) {
            return ['ok' => false, 'message' => "{$name} is already a workstation on this floor."];
        }

        $desk = $floor->workstations()->create(['name' => $name, 'status' => WorkstationStatus::Available]);

        return ['ok' => true, 'desk' => self::deskForMap($desk)];
    }

    /**
     * The modal behind a click on a desk.
     *
     * Mounted from the browser with `$wire.mountAction('deskDetails', {...})`
     * rather than rendered as a button, because the thing you click is a desk
     * on the map, not a row with an actions column.
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
            ->fillForm(function (array $arguments): array {
                $desk = $this->deskOnThisFloor((int) $arguments['workstation']);

                return [
                    ...$desk->only(Workstation::editableDetails()),
                    // Not a column: the switch the port is on, so the port
                    // dropdown opens already narrowed to it.
                    'switch_id' => $desk->switchPort?->network_switch_id,
                ];
            })
            ->schema(WorkstationDetailFields::make(
                floorId: fn (): int => $this->getRecord()->getKey(),
                deskId: fn (): int => $this->mountedDeskId(),
            ))
            ->action(function (array $arguments, array $data): void {
                abort_unless($this->canUpdateDesk((int) $arguments['workstation']), 403);

                $desk = $this->deskOnThisFloor((int) $arguments['workstation']);

                // Only the detail fields, taken by name. $data is shaped by
                // the schema, but writing it straight through would mean a
                // field added to that schema for display could reach update().
                $desk->update(
                    collect(Workstation::editableDetails())
                        ->mapWithKeys(fn (string $column): array => [$column => $data[$column] ?? null])
                        ->reject(fn (mixed $value, string $column): bool => $column === 'status' && $value === null)
                        ->all()
                );

                Notification::make()->title('Saved')->success()->send();

                // The map is a client-side draft behind wire:ignore and will not
                // see this write, so it is told which desk changed — its status
                // colour and details marker update without losing the draft.
                $desk->refresh();

                $this->dispatch(
                    'desk-details-saved',
                    workstation: $desk->getKey(),
                    hasDetails: $desk->hasDetails(),
                    desk: self::deskForMap($desk),
                );
            });
    }

    /**
     * Scoped through the floor's own relationship, so a crafted id cannot reach
     * a desk that belongs to a different floor.
     */
    protected function deskOnThisFloor(int $workstation): Workstation
    {
        /** @var Floor $floor */
        $floor = $this->getRecord();

        return $floor->workstations()->findOrFail($workstation);
    }

    /**
     * The desk the details modal is open on. The port uniqueness rule has to
     * ignore that desk, and the modal's form has no record of its own to ask.
     */
    protected function mountedDeskId(): int
    {
        $mounted = collect($this->mountedActions)->last(fn (array $action): bool => ($action['name'] ?? null) === 'deskDetails');

        return (int) ($mounted['arguments']['workstation'] ?? 0);
    }

    protected function canUpdateDesk(int $workstation): bool
    {
        return auth()->user()?->can('update', $this->deskOnThisFloor($workstation)) ?? false;
    }
}

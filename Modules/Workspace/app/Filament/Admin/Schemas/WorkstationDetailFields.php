<?php

namespace Modules\Workspace\Filament\Admin\Schemas;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;

/**
 * Everything recorded about a desk beyond its name and its floor.
 *
 * Defined once and used by every screen that edits a workstation — the plan's
 * details modal, the floor's Workstations tab, and the cross-floor resource —
 * so the fields never drift apart in label, validation or help text.
 *
 * Every choice is scoped to where the desk is: areas of its floor, switches in
 * its building, VLANs at its site. The screens tell this class which floor that
 * is, because each knows it differently — the plan and the floor tab are
 * standing on one, the resource form has it in a dropdown.
 *
 * Every field except status is optional. Empty is a normal state, not an
 * unfinished one.
 */
class WorkstationDetailFields
{
    /**
     * @param  Closure(Get): (int|string|null)  $floorId  the floor the desk is on
     * @param  (Closure(Get, ?Model): (int|null))|null  $deskId  the desk being edited, when the form has no record of its own
     * @return array<int, mixed>
     */
    public static function make(Closure $floorId, ?Closure $deskId = null, bool $withStatus = true): array
    {
        $floor = fn (Get $get): ?Floor => self::floor($floorId($get));

        return array_values(array_filter([
            $withStatus ? self::status() : null,

            Fieldset::make('Location')
                ->columns(4)
                ->schema([
                    Select::make('area_id')
                        ->label(Workstation::detailLabel('area'))
                        ->options(fn (Get $get): array => Area::query()
                            ->where('floor_id', $floor($get)?->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->rules([fn (Get $get) => Rule::exists('areas', 'id')->where('floor_id', $floor($get)?->getKey())])
                        ->createOptionForm([
                            TextInput::make('name')->label('Area name')->required()->maxLength(100),
                        ])
                        ->createOptionUsing(function (array $data, Get $get) use ($floor): int {
                            return Area::query()->firstOrCreate([
                                'floor_id' => $floor($get)?->getKey(),
                                'name' => trim($data['name']),
                            ])->getKey();
                        })
                        ->createOptionAction(fn (Action $action) => $action->authorize('create', Area::class)),

                    self::text('desk_row')->maxLength(20)->placeholder('e.g. 3'),
                    self::text('desk_position')->maxLength(20)->placeholder('e.g. 12'),
                    self::text('workstation_number')
                        ->maxLength(50)
                        ->placeholder('e.g. 214')
                        ->helperText('The number on the desk, if it differs from the ID.'),
                ]),

            Fieldset::make('Network')
                ->columns(3)
                ->schema([
                    // Not a column: choosing the switch narrows the ports, and
                    // the port is what the desk actually records.
                    Select::make('switch_id')
                        ->label(Workstation::detailLabel('switch'))
                        ->options(fn (Get $get): array => NetworkSwitch::query()
                            ->where('building_id', $floor($get)?->building_id)
                            ->orderBy('number')
                            ->get()
                            ->mapWithKeys(fn (NetworkSwitch $switch): array => [$switch->getKey() => $switch->label()])
                            ->all())
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, mixed $state, ?Model $record): void {
                            if (blank($state) && $record instanceof Workstation) {
                                $component->state($record->switchPort?->network_switch_id);
                            }
                        })
                        ->afterStateUpdated(fn (Set $set) => $set('switch_port_id', null))
                        ->createOptionForm([
                            TextInput::make('number')->label('Switch number')->placeholder('SW-03')->required()->maxLength(50),
                            TextInput::make('name')->label('Hostname')->maxLength(100),
                        ])
                        ->createOptionUsing(function (array $data, Get $get) use ($floor): int {
                            return NetworkSwitch::query()->firstOrCreate(
                                ['building_id' => $floor($get)?->building_id, 'number' => trim($data['number'])],
                                ['name' => $data['name'] ?? null],
                            )->getKey();
                        })
                        ->createOptionAction(fn (Action $action) => $action->authorize('create', NetworkSwitch::class)),

                    Select::make('switch_port_id')
                        ->label(Workstation::detailLabel('port'))
                        ->placeholder(fn (Get $get): string => filled($get('switch_id')) ? 'Select a port' : 'Choose the switch first')
                        ->options(fn (Get $get): array => filled($get('switch_id'))
                            ? SwitchPort::query()
                                ->where('network_switch_id', $get('switch_id'))
                                ->with('workstation.floor')
                                ->orderByRaw('LENGTH(name), name')
                                ->get()
                                ->mapWithKeys(fn (SwitchPort $port): array => [
                                    $port->getKey() => $port->workstation
                                        ? "{$port->name} — patched to {$port->workstation->displayLabel()}"
                                        : $port->name,
                                ])
                                ->all()
                            : [])
                        ->searchable()
                        ->rules([
                            // A port on a switch in this desk's building.
                            fn (Get $get) => Rule::exists('switch_ports', 'id')->whereIn(
                                'network_switch_id',
                                NetworkSwitch::query()->where('building_id', $floor($get)?->building_id)->pluck('id')->all(),
                            ),
                            // And not one another desk already holds.
                            fn (Get $get, ?Model $record) => Rule::unique('workstations', 'switch_port_id')
                                ->ignore($deskId ? $deskId($get, $record) : $record?->getKey()),
                        ])
                        ->validationMessages([
                            'unique' => 'Another workstation is already patched to this port.',
                        ])
                        ->createOptionForm([
                            TextInput::make('name')->label('Port name')->placeholder('Gi2/0/24')->required()->maxLength(50),
                            TextInput::make('number')->label('Port number')->placeholder('24')->maxLength(20),
                        ])
                        ->createOptionUsing(function (array $data, Get $get): int {
                            if (blank($get('switch_id'))) {
                                throw ValidationException::withMessages(['switch_port_id' => 'Choose the switch first.']);
                            }

                            return SwitchPort::query()->firstOrCreate(
                                ['network_switch_id' => $get('switch_id'), 'name' => trim($data['name'])],
                                ['number' => $data['number'] ?? null],
                            )->getKey();
                        })
                        ->createOptionAction(fn (Action $action) => $action->authorize('create', SwitchPort::class)),

                    self::text('port_split_number')
                        ->maxLength(50)
                        ->placeholder('e.g. 24 or A'),

                    Placeholder::make('rack')
                        ->label(Workstation::detailLabel('rack'))
                        ->content(fn (Get $get): string => filled($get('switch_id'))
                            ? (NetworkSwitch::query()->with('rack')->find($get('switch_id'))?->rack?->label() ?? 'The switch is not in a rack')
                            : '—')
                        ->helperText('Comes from the switch.'),

                    Select::make('vlan_id')
                        ->label(Workstation::detailLabel('vlan'))
                        ->options(fn (Get $get): array => Vlan::query()
                            ->where('site_id', $floor($get)?->building?->site_id)
                            ->orderBy('number')
                            ->get()
                            ->mapWithKeys(fn (Vlan $vlan): array => [$vlan->getKey() => $vlan->label()])
                            ->all())
                        ->searchable()
                        ->rules([fn (Get $get) => Rule::exists('vlans', 'id')->where('site_id', $floor($get)?->building?->site_id)]),

                    self::text('ip_address')
                        ->maxLength(45)
                        ->placeholder('e.g. 10.20.30.40')
                        ->rules(['ip']),

                    self::text('mac_address')
                        ->maxLength(23)
                        ->placeholder('e.g. AA:BB:CC:DD:EE:FF')
                        ->rules(['regex:'.Workstation::MAC_PATTERN])
                        ->validationMessages([
                            'regex' => 'Enter twelve hex digits — AA:BB:CC:DD:EE:FF, AA-BB-..., aabb.ccdd.eeff or AABBCCDDEEFF.',
                        ])
                        ->helperText('Saved as AA:BB:CC:DD:EE:FF whichever way it is pasted in.'),
                ]),

            Fieldset::make('Machine')
                ->columns(3)
                ->schema([
                    self::text('computer_name')->maxLength(100)->placeholder('e.g. ALX-PC-024'),
                    self::text('pc_serial')->maxLength(100),
                    self::text('monitor_serial')->maxLength(100),
                ]),

            Textarea::make('notes')
                ->label(Workstation::detailLabel('notes'))
                ->rows(3)
                ->maxLength(2000)
                ->dehydrateStateUsing(self::blankIsEmpty())
                ->columnSpanFull(),
        ]));
    }

    public static function status(): Select
    {
        return Select::make('status')
            ->label('Status')
            ->options(WorkstationStatus::class)
            ->default(WorkstationStatus::Active)
            ->required()
            ->native(false);
    }

    protected static function floor(int|string|null $id): ?Floor
    {
        return blank($id) ? null : Floor::query()->with('building')->find($id);
    }

    /**
     * A free-text identifier off a label, a switch table or a floor sheet.
     *
     * Text rather than a number even where the field is called one: real
     * labels are alphanumeric — Gi1/0/24, SW-03, A/B — and an input that
     * cannot hold what is printed on the equipment is not a record of it.
     */
    private static function text(string $name): TextInput
    {
        return TextInput::make($name)
            ->label(Workstation::detailLabel($name))
            ->dehydrateStateUsing(self::blankIsEmpty());
    }

    /**
     * Whitespace and empty strings are stored as null.
     *
     * Otherwise "cleared the field" and "never filled it in" become two
     * different states in the database that look identical on screen, and the
     * "has details" filter and the ring on the plan start disagreeing with
     * each other about the same desk.
     *
     * @return Closure(?string): ?string
     */
    private static function blankIsEmpty(): Closure
    {
        return function (?string $state): ?string {
            $state = trim((string) $state);

            return $state === '' ? null : $state;
        };
    }
}

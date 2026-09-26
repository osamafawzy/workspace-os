<?php

namespace Modules\Workspace\Imports;

use App\Models\User;
use App\Support\Import\ImportColumn;
use App\Support\Import\Importer;
use App\Support\Import\ImportRunner;
use App\Support\Import\RowCheck;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;
use Modules\Workspace\Models\Workstation;

/**
 * Workstations from a patching sheet.
 *
 * A row names its floor (and building, when floor names repeat across
 * buildings) and gives the desk's ID; everything else is optional. Areas,
 * switches, ports, racks and VLANs are named the way the sheet names them, and
 * found — or, if the option is on, created — in the desk's own floor, building
 * and site.
 *
 * The headings match the workstation export, so a sheet exported, corrected in
 * Excel and imported again updates the same desks.
 */
class WorkstationImporter extends Importer
{
    /** @var Collection<int, Floor> */
    protected Collection $floors;

    /** @var array<string, mixed> lookups found once per run, including "not there" */
    protected array $memo = [];

    /** @var array<string, int> ports claimed by rows so far: "switch|port" => row key */
    protected array $claimedPorts = [];

    public static function key(): string
    {
        return 'workstations';
    }

    public static function label(): string
    {
        return 'Workstations';
    }

    public function authorize(User $user): bool
    {
        return $user->can('import', Workstation::class);
    }

    public function columns(): array
    {
        return [
            new ImportColumn('building', 'Building', aliases: ['Building Name'], example: 'HQ Tower B'),
            new ImportColumn('floor', 'Floor', required: true, aliases: ['Floor Name'], example: 'Floor 2'),
            new ImportColumn('area', 'Area / Zone', aliases: ['Area', 'Zone', 'Zone Number', 'Area Zone'], example: 'Operations Floor'),
            new ImportColumn('name', 'Workstation ID', required: true, aliases: ['Workstation', 'WS ID', 'Desk', 'Desk ID', 'Name'], example: 'WS-024'),
            new ImportColumn('status', 'Status', example: 'Active'),
            new ImportColumn('workstation_number', 'Workstation Number', aliases: ['WS Number', 'WS No', 'Desk Number'], example: '24'),
            new ImportColumn('desk_row', 'Row', aliases: ['Desk Row'], example: '3'),
            new ImportColumn('desk_position', 'Position', aliases: ['Desk Position', 'Seat'], example: '12'),
            new ImportColumn('switch', 'Switch', aliases: ['Switch Number', 'Switch Name', 'Switch No'], example: 'SW-02'),
            new ImportColumn('port', 'Port', aliases: ['Port Name', 'Interface', 'Interface Number', 'Switch Port'], example: 'Gi2/0/24'),
            new ImportColumn('port_number', 'Port Number', aliases: ['Port No'], example: '24'),
            new ImportColumn('port_split_number', 'Port Split Number', aliases: ['Port Split', 'Split'], example: '24'),
            new ImportColumn('rack', 'Rack', aliases: ['Rack Number', 'Rack No'], example: 'RACK-02'),
            new ImportColumn('vlan', 'VLAN', aliases: ['VLAN ID', 'VLAN Number'], example: '123'),
            new ImportColumn('computer_name', 'PC Name', aliases: ['Computer Name', 'Computer', 'Hostname', 'PC'], example: 'ALX-PC-024'),
            new ImportColumn('pc_serial', 'PC Serial Number', aliases: ['PC Serial', 'Computer Serial'], example: 'PC00012345'),
            new ImportColumn('monitor_serial', 'Monitor Serial Number', aliases: ['Monitor Serial'], example: 'MON0012345'),
            new ImportColumn('ip_address', 'IP Address', aliases: ['IP'], example: '10.20.30.40'),
            new ImportColumn('mac_address', 'MAC Address', aliases: ['MAC'], example: 'AA:BB:CC:DD:EE:FF'),
            new ImportColumn('notes', 'Notes', aliases: ['Comments', 'Remarks'], example: ''),
        ];
    }

    public function optionFields(): array
    {
        return [
            Toggle::make('create_missing')
                ->label('Create areas, switches, ports, racks and VLANs that do not exist yet')
                ->helperText('Off: a row naming one that does not exist is marked invalid instead.')
                ->default(true),
        ];
    }

    public function defaultOptions(): array
    {
        return ['create_missing' => true, 'existing' => ImportRunner::EXISTING_SKIP];
    }

    public function returnUrl(): ?string
    {
        return WorkstationResource::getUrl('index');
    }

    public function prepare(array $options): void
    {
        $this->floors = Floor::query()->with('building')->get();
        $this->memo = [];
        $this->claimedPorts = [];
    }

    public function check(array $row, RowCheck $check, array $options): void
    {
        $create = (bool) ($options['create_missing'] ?? true);
        $updating = ($options['existing'] ?? ImportRunner::EXISTING_SKIP) === ImportRunner::EXISTING_UPDATE;

        // ---- where ---------------------------------------------------------
        $floor = $this->findFloor($row['floor'], $row['building'], $check);
        $name = $row['name'];

        if ($name === '') {
            $check->error('Workstation ID is empty.');
        } elseif (mb_strlen($name) > 100) {
            $check->error('Workstation ID is longer than 100 characters.');
        }

        if (! $floor || $name === '') {
            $this->checkValues($row, $check);

            return;
        }

        $check->key = $floor->getKey().'|'.mb_strtolower($name);
        $check->existing = $this->remember('desk:'.$check->key, fn () => Workstation::query()
            ->where('floor_id', $floor->getKey())
            ->where('name', $name)
            ->first());

        if ($row['area'] !== '' && ! $this->area($floor, $row['area'])) {
            $create
                ? $check->warning("Area \"{$row['area']}\" will be created on {$floor->name}.")
                : $check->error("Area \"{$row['area']}\" does not exist on {$floor->name}.");
        }

        // ---- patching ------------------------------------------------------
        if ($row['port'] !== '' && $row['switch'] === '') {
            $check->error('A port is given without its switch.');
        }

        if ($row['switch'] !== '') {
            $switch = $this->switch($floor, $row['switch']);

            if (! $switch) {
                $create
                    ? $check->warning("Switch {$row['switch']} will be created in {$floor->building->name}.")
                    : $check->error("Switch {$row['switch']} does not exist in {$floor->building->name}.");
            }

            if ($row['rack'] !== '') {
                $rack = $this->rack($floor, $row['rack']);

                if (! $rack && ! $create) {
                    $check->error("Rack {$row['rack']} does not exist in {$floor->building->name}.");
                } elseif (! $rack) {
                    $check->warning("Rack {$row['rack']} will be created in {$floor->building->name}.");
                }

                if ($switch && $switch->rack_id && $rack && $switch->rack_id !== $rack->getKey()) {
                    $check->warning("{$switch->number} is recorded in a different rack; its rack is left as it is.");
                }
            }

            if ($row['port'] !== '') {
                $claim = mb_strtolower($floor->building_id.'|'.$row['switch'].'|'.$row['port']);

                if (isset($this->claimedPorts[$claim]) && $this->claimedPorts[$claim] !== $check->key) {
                    $check->error("Port {$row['switch']} {$row['port']} is also used by another row of this file.");
                }

                $this->claimedPorts[$claim] ??= $check->key;

                $port = $switch ? $this->port($switch, $row['port']) : null;

                if ($switch && ! $port) {
                    $create
                        ? $check->warning("Port {$row['port']} will be created on {$switch->number}.")
                        : $check->error("Port {$row['port']} does not exist on {$switch->number}.");
                }

                if ($port?->workstation && ! $port->workstation->is($check->existing)) {
                    $check->error("Port {$switch->number} {$port->name} is already patched to {$port->workstation->displayLabel()}.");
                }
            }
        } elseif ($row['rack'] !== '') {
            $check->warning('A rack without a switch is not recorded against a desk; the rack is ignored.');
        }

        if ($row['vlan'] !== '') {
            if (! ctype_digit($row['vlan']) || (int) $row['vlan'] < 1 || (int) $row['vlan'] > 4094) {
                $check->error("VLAN \"{$row['vlan']}\" is not a VLAN ID between 1 and 4094.");
            } elseif (! $this->vlan($floor, (int) $row['vlan'])) {
                $create
                    ? $check->warning("VLAN {$row['vlan']} will be created at {$floor->building->site?->name}.")
                    : $check->error("VLAN {$row['vlan']} does not exist at {$floor->building->site?->name}.");
            }
        }

        $this->checkValues($row, $check);

        if ($check->existing && $updating) {
            $check->warning('Updates the existing workstation. Empty cells leave its current values alone.');
        }
    }

    public function save(array $row, ?Model $existing, array $options): Model
    {
        $floor = $this->findFloor($row['floor'], $row['building'], new RowCheck);
        $values = [];

        if ($row['area'] !== '') {
            $values['area_id'] = Area::query()->firstOrCreate(['floor_id' => $floor->getKey(), 'name' => $row['area']])->getKey();
        }

        if ($row['switch'] !== '') {
            $switch = $this->switch($floor, $row['switch']);

            if (! $switch) {
                $rack = $row['rack'] !== ''
                    ? Rack::query()->firstOrCreate(
                        ['building_id' => $floor->building_id, 'number' => $row['rack']],
                        ['floor_id' => $floor->getKey()],
                    )
                    : null;

                $switch = NetworkSwitch::query()->firstOrCreate(
                    ['building_id' => $floor->building_id, 'number' => $row['switch']],
                    ['rack_id' => $rack?->getKey()],
                );
            } elseif (! $switch->rack_id && $row['rack'] !== '') {
                // A switch with no rack yet gets the one the sheet names.
                $switch->update(['rack_id' => Rack::query()->firstOrCreate(
                    ['building_id' => $floor->building_id, 'number' => $row['rack']],
                    ['floor_id' => $floor->getKey()],
                )->getKey()]);
            }

            unset($this->memo['switch:'.$floor->building_id.'|'.mb_strtolower($row['switch'])]);

            if ($row['port'] !== '') {
                $values['switch_port_id'] = SwitchPort::query()->firstOrCreate(
                    ['network_switch_id' => $switch->getKey(), 'name' => $row['port']],
                    ['number' => $row['port_number'] !== '' ? $row['port_number'] : null],
                )->getKey();
            }
        }

        if ($row['vlan'] !== '') {
            $values['vlan_id'] = Vlan::query()->firstOrCreate(
                ['site_id' => $floor->building->site_id, 'number' => (int) $row['vlan']],
            )->getKey();
        }

        foreach (['workstation_number', 'desk_row', 'desk_position', 'port_split_number', 'computer_name', 'pc_serial', 'monitor_serial', 'ip_address', 'mac_address', 'notes'] as $field) {
            if ($row[$field] !== '') {
                $values[$field] = $row[$field];
            }
        }

        if ($row['status'] !== '') {
            $values['status'] = WorkstationStatus::fromLoose($row['status']);
        }

        if ($existing instanceof Workstation) {
            // Empty cells leave the existing value alone.
            $existing->update($values);

            return $existing;
        }

        return Workstation::query()->create([
            'floor_id' => $floor->getKey(),
            'name' => $row['name'],
            'status' => WorkstationStatus::Active,
            ...$values,
        ]);
    }

    /** The values that stand on their own: formats and lengths. */
    protected function checkValues(array $row, RowCheck $check): void
    {
        if ($row['status'] !== '' && ! WorkstationStatus::fromLoose($row['status'])) {
            $check->error("Status \"{$row['status']}\" is not one of: ".collect(WorkstationStatus::cases())->map->getLabel()->implode(', ').'.');
        }

        if ($row['ip_address'] !== '' && filter_var($row['ip_address'], FILTER_VALIDATE_IP) === false) {
            $check->error("\"{$row['ip_address']}\" is not an IP address.");
        }

        if ($row['mac_address'] !== '' && ! preg_match(Workstation::MAC_PATTERN, $row['mac_address'])) {
            $check->error("\"{$row['mac_address']}\" is not a MAC address.");
        }

        $limits = [
            'area' => 100, 'workstation_number' => 50, 'desk_row' => 20, 'desk_position' => 20,
            'switch' => 50, 'port' => 50, 'port_number' => 20, 'port_split_number' => 50, 'rack' => 50,
            'computer_name' => 100, 'pc_serial' => 100, 'monitor_serial' => 100, 'notes' => 2000,
        ];

        foreach ($limits as $field => $limit) {
            if (mb_strlen($row[$field]) > $limit) {
                $check->error("{$field} is longer than {$limit} characters.");
            }
        }
    }

    protected function findFloor(string $floorName, string $buildingName, RowCheck $check): ?Floor
    {
        if ($floorName === '') {
            $check->error('Floor is empty.');

            return null;
        }

        $matches = $this->floors->filter(fn (Floor $floor): bool => mb_strtolower($floor->name) === mb_strtolower($floorName));

        if ($buildingName !== '') {
            $matches = $matches->filter(fn (Floor $floor): bool => in_array(
                mb_strtolower($buildingName),
                [mb_strtolower($floor->building->name), mb_strtolower((string) $floor->building->code)],
                true,
            ));
        }

        if ($matches->isEmpty()) {
            $check->error($buildingName !== ''
                ? "There is no floor \"{$floorName}\" in building \"{$buildingName}\"."
                : "There is no floor \"{$floorName}\". Add it under Floor Setup first.");

            return null;
        }

        if ($matches->count() > 1) {
            $check->error("More than one building has a floor called \"{$floorName}\". Add a Building column to say which.");

            return null;
        }

        return $matches->first();
    }

    protected function area(Floor $floor, string $name): ?Area
    {
        return $this->remember('area:'.$floor->getKey().'|'.mb_strtolower($name), fn () => Area::query()
            ->where('floor_id', $floor->getKey())
            ->where('name', $name)
            ->first());
    }

    /** A switch in the floor's building, by the number on it or its hostname. */
    protected function switch(Floor $floor, string $label): ?NetworkSwitch
    {
        return $this->remember('switch:'.$floor->building_id.'|'.mb_strtolower($label), fn () => NetworkSwitch::query()
            ->where('building_id', $floor->building_id)
            ->where(fn ($query) => $query->where('number', $label)->orWhere('name', $label))
            ->first());
    }

    protected function port(NetworkSwitch $switch, string $name): ?SwitchPort
    {
        return $this->remember('port:'.$switch->getKey().'|'.mb_strtolower($name), fn () => SwitchPort::query()
            ->with('workstation.floor')
            ->where('network_switch_id', $switch->getKey())
            ->where('name', $name)
            ->first());
    }

    protected function rack(Floor $floor, string $number): ?Rack
    {
        return $this->remember('rack:'.$floor->building_id.'|'.mb_strtolower($number), fn () => Rack::query()
            ->where('building_id', $floor->building_id)
            ->where('number', $number)
            ->first());
    }

    protected function vlan(Floor $floor, int $number): ?Vlan
    {
        return $this->remember('vlan:'.$floor->building->site_id.'|'.$number, fn () => Vlan::query()
            ->where('site_id', $floor->building->site_id)
            ->where('number', $number)
            ->first());
    }

    /**
     * A lookup made once per run. "Not there" is remembered too, so a sheet
     * naming the same missing switch three hundred times asks once.
     *
     * @template T
     *
     * @param  callable(): (T|null)  $find
     * @return T|null
     */
    protected function remember(string $key, callable $find): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $find() ?? false;
        }

        return $this->memo[$key] ?: null;
    }
}

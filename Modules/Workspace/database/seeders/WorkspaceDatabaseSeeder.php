<?php

namespace Modules\Workspace\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Settings\Models\Site;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Actions\CreateWorkstationBatch;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Area;
use Modules\Workspace\Models\Building;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\NetworkSwitch;
use Modules\Workspace\Models\Rack;
use Modules\Workspace\Models\SwitchPort;
use Modules\Workspace\Models\Vlan;

class WorkspaceDatabaseSeeder extends Seeder
{
    protected Building $building;

    /**
     * A building to click around in.
     *
     * Four floors, each there to exercise something different:
     *
     *   - the ground floor is drawn against a real architect's plan, with its
     *     desks standing in the zones that drawing marks out. It is the case
     *     the product is actually for, and the only one that shows whether a
     *     coordinate means anything once there is a room under it;
     *   - two ordinary floors have desks in two banks either side of a walkway,
     *     on the blank grid — a real floor is not a lattice, and a demo that
     *     renders one says nothing about whether placement works;
     *   - one open-plan floor holds three hundred desks, because that is the
     *     size this has to stay usable at and it is not worth setting up by
     *     hand to find out.
     *
     * Every floor has a rack, switches with ports, a VLAN and areas, so every
     * filter and every column has something real to show.
     *
     * Seeding is idempotent: re-running it does not produce "Ground Floor"
     * twice, and it does not move desks that have already been positioned.
     */
    public function run(): void
    {
        $this->building = $this->building();

        $this->groundFloor();

        $floors = [
            ['name' => 'First Floor', 'level' => 1, 'prefix' => 'A-', 'desks' => 8, 'w' => 24, 'd' => 16],
            ['name' => 'Second Floor', 'level' => 2, 'prefix' => 'B-', 'desks' => 4, 'w' => 24, 'd' => 16],
        ];

        foreach ($floors as $spec) {
            $floor = $this->floor($spec['level'], [
                'name' => $spec['name'],
                'width_m' => $spec['w'],
                'depth_m' => $spec['d'],
                'is_active' => true,
            ]);

            $positions = [];

            foreach ($this->banks($spec['desks']) as $index => $position) {
                // The last desk on each floor is left untraced on purpose, so
                // every screen that distinguishes "recorded" from "not yet" —
                // the ring on the plan, the list badge, the filter, the empty
                // modal — has both states to show without setting one up.
                $traced = $index < $spec['desks'] - 1;

                $desk = $floor->workstations()->updateOrCreate(
                    ['name' => $spec['prefix'].str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)],
                    $traced ? $this->patching($floor, $index) : $this->untraced(),
                );

                // Already on the map from an earlier seed: left where it is.
                if (! $desk->isPlaced()) {
                    $positions[$desk->getKey()] = $position;
                }
            }

            app(ArrangeWorkstations::class)->write($floor, $positions);
            $this->furnish($floor);
        }

        $this->openPlanFloor();
    }

    /**
     * The one building the demo lives in.
     *
     * Found by name first, so a database that was migrated from before
     * buildings existed — whose floors were moved into "HQ Tower B" under its
     * first site — is re-seeded in place rather than given a second building.
     */
    protected function building(): Building
    {
        return Building::query()->where('name', 'HQ Tower B')->first()
            ?? Building::query()->create([
                'site_id' => (Site::query()->orderBy('id')->first()
                    ?? Site::query()->create(['name' => 'Alexandria Site', 'code' => 'ALX', 'city' => 'Alexandria']))->getKey(),
                'name' => 'HQ Tower B',
                'code' => 'HQB',
            ]);
    }

    /**
     * A floor made the first time; left as it is after that. Somebody may have
     * resized or renamed it in the panel, and resizing a floor rescales its map.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function floor(int $level, array $attributes): Floor
    {
        return Floor::query()->firstOrCreate(
            ['building_id' => $this->building->getKey(), 'level' => $level],
            $attributes,
        );
    }

    /**
     * The ground floor, drawn against the real plan of it.
     *
     * The drawing is the coordinate space, so the desks are placed in the four
     * zones the drawing itself labels rather than laid out on a grid over the
     * top of it. A grid would have looked exactly as convincing on a blank
     * page, which is the whole thing this floor is here to disprove.
     *
     * Built the way a person builds it in the panel — create a run of desks,
     * then draw a box round a bank on the drawing and fill it — because this
     * calls the same action **Fill an area** does. If the seeder could produce
     * a floor nobody using the product could reproduce, the demo would be
     * showing something that is not there.
     */
    protected function groundFloor(): void
    {
        $floor = $this->floor(0, [
            'name' => 'Ground Floor',
            // Read off the drawing: 1088 x 778 is a 1.4 room, and 70 x 50 m
            // is the size of floor plate that holds the four hundred seats it
            // marks out.
            'width_m' => 70,
            'depth_m' => 50,
            'description' => 'Four zones either side of the central atrium, plus training rooms along the west wall.',
            'is_active' => true,
        ]);

        $this->attachPlan($floor);

        $arrange = app(ArrangeWorkstations::class);
        $seat = 0;

        foreach ($this->zones() as $zone => [$box, $columns, $rows]) {
            $count = $columns * $rows;

            for ($index = 0; $index < $count; $index++) {
                $seat++;

                $floor->workstations()->updateOrCreate(
                    ['name' => 'G-'.str_pad((string) $seat, 2, '0', STR_PAD_LEFT)],
                    // The last desk in each zone is left untraced on purpose,
                    // so every screen that distinguishes "recorded" from "not
                    // yet" — the colour of the marker on the plan, the list
                    // badge, the filter, the empty modal — has both to show.
                    $index < $count - 1
                        // The zone on the sheet is the zone printed on the
                        // drawing, not a number worked out from a loop counter.
                        ? $this->patching($floor, $seat - 1, 'Zone '.$zone)
                        : $this->untraced(),
                );
            }

            // Takes the desks just created off the tray, in name order, and
            // spreads them across the bank. Already-placed desks are left
            // alone, which is what makes a re-seed a no-op.
            $arrange->fill($floor, $box, $columns, $rows);
        }
    }

    /**
     * The plan drawing itself, put on the public disk where the app serves
     * uploads from. Copied rather than referenced in place: the drawing that
     * ships with the seeder is a fixture, and a floor's plan_path has to point
     * at something an admin could later replace through the upload field.
     */
    protected function attachPlan(Floor $floor): void
    {
        $path = 'floor-plans/ground-floor.png';
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $disk->put($path, (string) file_get_contents(__DIR__.'/plans/ground-floor.png'));
        }

        if ($floor->plan_path !== $path) {
            $floor->update(['plan_path' => $path]);
        }
    }

    /**
     * The four desk zones of the ground floor drawing, as percentages of it.
     *
     * Read off the plan by eye, which is exactly how somebody would place these
     * by dragging them — the point is that they sit on the banks the drawing
     * shows, not that they are surveyed.
     *
     * @return array<string, array{0: array{0: float, 1: float, 2: float, 3: float}, 1: int, 2: int}>
     */
    protected function zones(): array
    {
        return [
            'A' => [[26.0, 5.0, 55.0, 14.0], 8, 3],
            'B' => [[41.0, 85.0, 65.0, 95.0], 6, 3],
            'C' => [[70.0, 5.0, 97.0, 14.0], 6, 3],
            'D' => [[80.0, 63.0, 97.0, 75.0], 4, 3],
        ];
    }

    /**
     * The rest of what is on a floor besides desks: its outside walls, a door,
     * the fire exits and a name on the floor; and on the small floors, which
     * have room for them, an IT room with its rack, a meeting room and a
     * printer. Enough for the map editor to have every kind of object to show.
     *
     * Only once per floor — a floor that already has anything but desks on its
     * map is left alone, so re-seeding never stacks a second set of walls.
     */
    protected function furnish(Floor $floor): void
    {
        if ($floor->mapObjects()->where('type', '!=', 'workstation')->exists()) {
            return;
        }

        $w = $floor->width_m;
        $d = $floor->depth_m;
        $wall = fn (float $x, float $y, float $length, float $rotation): array => [
            'type' => 'wall', 'x' => $x, 'y' => $y, 'width' => $length, 'depth' => 0.2, 'height' => 2.8, 'rotation' => $rotation,
        ];

        $objects = [
            $wall($w / 2, 0, $w, 0),
            $wall($w / 2, $d, $w, 0),
            $wall(0, $d / 2, $d, 90),
            $wall($w, $d / 2, $d, 90),
            ['type' => 'door', 'x' => $w / 2, 'y' => $d, 'width' => 1.8, 'depth' => 0.2, 'height' => 2.1, 'props' => ['swing' => 'left']],
            ['type' => 'emergency-exit', 'x' => 1.2, 'y' => $d - 0.6, 'width' => 1.2, 'depth' => 0.5, 'props' => ['text' => 'EXIT']],
            ['type' => 'emergency-exit', 'x' => $w - 1.2, 'y' => 0.6, 'width' => 1.2, 'depth' => 0.5, 'props' => ['text' => 'EXIT']],
            ['type' => 'text', 'x' => $w / 2, 'y' => $d - 1.2, 'width' => 4, 'depth' => 0.8, 'label' => $floor->name, 'props' => ['text' => $floor->name, 'size' => 0.6]],
        ];

        if ($w <= 30) {
            $objects[] = ['type' => 'it-room', 'x' => 2.2, 'y' => 1.6, 'width' => 4, 'depth' => 3, 'label' => 'IT room'];
            $objects[] = ['type' => 'rack', 'x' => 1.0, 'y' => 1.0, 'width' => 0.6, 'depth' => 1.0, 'height' => 2.0, 'label' => sprintf('RACK-%02d', $floor->level + 1)];
            $objects[] = ['type' => 'meeting-room', 'x' => $w - 2.8, 'y' => $d - 2.4, 'width' => 5, 'depth' => 4, 'label' => 'Meeting room'];
            $objects[] = ['type' => 'printer', 'x' => $w / 2, 'y' => 1.0, 'width' => 0.6, 'depth' => 0.5, 'height' => 1.0, 'label' => 'Printer'];
        }

        foreach ($objects as $object) {
            $floor->mapObjects()->create([
                'z' => 0,
                'height' => 0,
                'rotation' => 0,
                ...$object,
            ]);
        }
    }

    /**
     * The floor this system exists for: 60 x 40 m, three hundred desks.
     *
     * Created through the same bulk-add and auto-arrange the admin uses, so the
     * demo exercises the real path rather than a seeder-only shortcut.
     */
    protected function openPlanFloor(): void
    {
        $floor = $this->floor(3, [
            'name' => 'Third Floor',
            'width_m' => 60,
            'depth_m' => 40,
            'description' => 'Open plan. The floor this system is built to hold.',
            'is_active' => true,
        ]);

        if ($floor->workstations()->count() >= 300) {
            $this->furnish($floor);

            return;
        }

        app(CreateWorkstationBatch::class)->handle($floor, 'C-', 300);
        app(ArrangeWorkstations::class)->handle($floor);
        $this->furnish($floor);

        // Two thirds traced, a third still to do. A floor where every desk is
        // documented shows the modal but hides the question the ring on the
        // plan and the "has details" filter exist to answer.
        $floor->workstations()->orderBy('name')->get()
            ->each(function ($desk, int $index) use ($floor): void {
                if ($index % 3 === 2) {
                    $desk->update(['status' => WorkstationStatus::Available]);

                    return;
                }

                $desk->update($this->patching($floor, $index));
            });
    }

    /**
     * A plausible record for one desk.
     *
     * Derived from the floor and the desk's place on it rather than randomised,
     * so a re-seed does not rewrite the whole building with different numbers
     * and a screenshot taken today still matches one taken tomorrow.
     *
     * @return array<string, mixed>
     */
    protected function patching(Floor $floor, int $index, ?string $area = null): array
    {
        $level = $floor->level;
        $switchNumber = intdiv($index, 24) + 1;

        $rack = Rack::query()->firstOrCreate(
            ['building_id' => $this->building->getKey(), 'number' => sprintf('RACK-%02d', $level + 1)],
            ['floor_id' => $floor->getKey(), 'name' => "{$floor->name} comms room"],
        );

        $switch = NetworkSwitch::query()->firstOrCreate(
            ['building_id' => $this->building->getKey(), 'number' => sprintf('SW-%02d', ($level * 10) + $switchNumber)],
            [
                'rack_id' => $rack->getKey(),
                'name' => sprintf('HQB-L%d-ACC-%02d', $level, $switchNumber),
                'model' => 'Catalyst 9200-48P',
                'port_count' => 48,
            ],
        );

        // Each switch serves 24 consecutive desks, so these never collide on
        // one switch.
        $port = SwitchPort::query()->firstOrCreate(
            ['network_switch_id' => $switch->getKey(), 'name' => 'Gi1/0/'.(($index % 48) + 1)],
            ['number' => (string) (($index % 48) + 1)],
        );

        $vlan = Vlan::query()->firstOrCreate(
            ['site_id' => $this->building->site_id, 'number' => 100 + $level],
            ['name' => $floor->name, 'subnet' => "10.20.{$level}.0/23"],
        );

        return [
            'area_id' => $this->area($floor, $area ?? 'Zone Z'.($level + 1).'-'.(intdiv($index, 12) + 1))->getKey(),
            'status' => match (true) {
                $index % 25 === 24 => WorkstationStatus::Faulty,
                $index % 40 === 39 => WorkstationStatus::Offline,
                default => WorkstationStatus::Active,
            },
            'workstation_number' => (string) ($index + 1),
            'desk_row' => (string) (intdiv($index, 12) + 1),
            'desk_position' => (string) (($index % 12) + 1),
            'switch_port_id' => $port->getKey(),
            // Every fourth desk has its switch port traced but not the split
            // — a half-finished row is the normal state of a patching sheet,
            // and the demo should show one.
            'port_split_number' => $index % 4 === 3 ? null : ($index % 2 === 0 ? 'A' : 'B'),
            'vlan_id' => $vlan->getKey(),
            'computer_name' => sprintf('HQ-L%d-WS%03d', $level, $index + 1),
            'pc_serial' => sprintf('PC%d%05d', $level, $index + 1),
            'monitor_serial' => sprintf('MON%d%05d', $level, $index + 1),
            'ip_address' => sprintf('10.20.%d.%d', ($level * 2) + intdiv($index, 250), ($index % 250) + 1),
            // A made-up MAC in the locally administered range, so nothing here
            // can collide with a real vendor's address block.
            'mac_address' => sprintf('02:00:00:%02X:%02X:%02X', $level, intdiv($index, 256), $index % 256),
        ];
    }

    /**
     * Every detail empty: a desk that exists and has not been traced.
     *
     * @return array<string, mixed>
     */
    protected function untraced(): array
    {
        return [
            'area_id' => null,
            'status' => WorkstationStatus::Available,
            'workstation_number' => null,
            'desk_row' => null,
            'desk_position' => null,
            'switch_port_id' => null,
            'port_split_number' => null,
            'vlan_id' => null,
            'computer_name' => null,
            'pc_serial' => null,
            'monitor_serial' => null,
            'ip_address' => null,
            'mac_address' => null,
        ];
    }

    protected function area(Floor $floor, string $name): Area
    {
        return Area::query()->firstOrCreate(['floor_id' => $floor->getKey(), 'name' => $name]);
    }

    /**
     * Two banks of desks with a walkway between them.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    protected function banks(int $count): array
    {
        $perBank = (int) ceil($count / 2);
        $positions = [];

        for ($i = 0; $i < $count; $i++) {
            $bank = intdiv($i, $perBank);
            $seat = $i % $perBank;

            $positions[] = [
                // Two columns at 28% and 72%, leaving the middle clear.
                $bank === 0 ? 28.0 : 72.0,
                // Evenly down the room, inset from both walls.
                round($perBank > 1 ? 20.0 + (60.0 * $seat / ($perBank - 1)) : 50.0, 2),
            ];
        }

        return $positions;
    }
}

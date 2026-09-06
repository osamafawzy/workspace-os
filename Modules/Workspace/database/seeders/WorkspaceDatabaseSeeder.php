<?php

namespace Modules\Workspace\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Actions\CreateWorkstationBatch;
use Modules\Workspace\Models\Floor;

class WorkspaceDatabaseSeeder extends Seeder
{
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
     * Seeding is idempotent: re-running it does not produce "Ground Floor"
     * twice, and it does not move desks that have already been positioned.
     */
    public function run(): void
    {
        $this->groundFloor();

        $building = [
            ['name' => 'First Floor', 'level' => 1, 'prefix' => 'A-', 'desks' => 8, 'w' => 24, 'd' => 16],
            ['name' => 'Second Floor', 'level' => 2, 'prefix' => 'B-', 'desks' => 4, 'w' => 24, 'd' => 16],
        ];

        foreach ($building as $spec) {
            $floor = Floor::query()->updateOrCreate(
                ['level' => $spec['level']],
                [
                    'name' => $spec['name'],
                    'width_m' => $spec['w'],
                    'depth_m' => $spec['d'],
                    'is_active' => true,
                ],
            );

            foreach ($this->banks($spec['desks']) as $index => [$x, $y]) {
                // The last desk on each floor is left untraced on purpose, so
                // every screen that distinguishes "recorded" from "not yet" —
                // the ring on the plan, the list badge, the filter, the empty
                // modal — has both states to show without setting one up.
                $traced = $index < $spec['desks'] - 1;

                $floor->workstations()->updateOrCreate(
                    ['name' => $spec['prefix'].str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)],
                    ['position_x' => $x, 'position_y' => $y]
                        + ($traced ? $this->patching($floor, $index) : array_fill_keys(array_keys($this->patching($floor, $index)), null)),
                );
            }
        }

        $this->openPlanFloor();
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
        $floor = Floor::query()->updateOrCreate(
            ['level' => 0],
            [
                'name' => 'Ground Floor',
                // Read off the drawing: 1088 x 778 is a 1.4 room, and 70 x 50 m
                // is the size of floor plate that holds the four hundred seats
                // it marks out.
                'width_m' => 70,
                'depth_m' => 50,
                'description' => 'Four zones either side of the central atrium, plus training rooms along the west wall.',
                'is_active' => true,
            ],
        );

        $this->attachPlan($floor);

        $arrange = app(ArrangeWorkstations::class);
        $seat = 0;

        foreach ($this->zones() as $zone => [$box, $columns, $rows]) {
            $count = $columns * $rows;

            for ($index = 0; $index < $count; $index++) {
                $seat++;
                $record = $this->patching($floor, $seat - 1);

                $floor->workstations()->updateOrCreate(
                    ['name' => 'G-'.str_pad((string) $seat, 2, '0', STR_PAD_LEFT)],
                    // The last desk in each zone is left untraced on purpose,
                    // so every screen that distinguishes "recorded" from "not
                    // yet" — the colour of the marker on the plan, the list
                    // badge, the filter, the empty modal — has both to show.
                    $index < $count - 1
                        // The zone on the sheet is the zone printed on the
                        // drawing, not a number worked out from a loop counter.
                        ? ['zone_number' => 'Zone '.$zone] + $record
                        : array_fill_keys(array_keys($record), null),
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
     * The floor this system exists for: 60 x 40 m, three hundred desks.
     *
     * Created through the same bulk-add and auto-arrange the admin uses, so the
     * demo exercises the real path rather than a seeder-only shortcut.
     */
    protected function openPlanFloor(): void
    {
        $floor = Floor::query()->updateOrCreate(
            ['level' => 3],
            [
                'name' => 'Third Floor',
                'width_m' => 60,
                'depth_m' => 40,
                'description' => 'Open plan. The floor this system is built to hold.',
                'is_active' => true,
            ],
        );

        if ($floor->workstations()->count() >= 300) {
            return;
        }

        app(CreateWorkstationBatch::class)->handle($floor, 'C-', 300);
        app(ArrangeWorkstations::class)->handle($floor);

        // Two thirds traced, a third still to do. A floor where every desk is
        // documented shows the modal but hides the question the ring on the
        // plan and the "has details" filter exist to answer.
        $floor->workstations()->orderBy('name')->get()
            ->each(function ($desk, int $index) use ($floor): void {
                if ($index % 3 === 2) {
                    return;
                }

                $desk->update($this->patching($floor, $index));
            });
    }

    /**
     * A plausible patching record for one desk.
     *
     * Derived from the floor and the desk's place on it rather than randomised,
     * so a re-seed does not rewrite the whole building with different numbers
     * and a screenshot taken today still matches one taken tomorrow.
     *
     * @return array<string, string|null>
     */
    protected function patching(Floor $floor, int $index): array
    {
        $zone = intdiv($index, 12) + 1;
        $switch = intdiv($index, 24) + 1;

        return [
            'site_location' => 'HQ Tower B',
            'zone_number' => 'Z'.($floor->level + 1).'-'.$zone,
            'workstation_number' => (string) ($index + 1),
            // Every fourth desk has its switch port traced but not which side
            // of the split it takes — a half-finished row is the normal state
            // of a patching sheet, and the demo should show one.
            'port_split_number' => $index % 4 === 3 ? null : ($index % 2 === 0 ? 'A' : 'B'),
            'switch_number' => sprintf('SW-%02d', ($floor->level * 10) + $switch),
            'interface_number' => 'Gi1/0/'.(($index % 48) + 1),
            'computer_name' => sprintf('HQ-L%d-WS%03d', $floor->level, $index + 1),
            // A made-up MAC in the locally administered range, so nothing here
            // can collide with a real vendor's address block.
            'mac_address' => sprintf('02:00:00:%02X:%02X:%02X', $floor->level, intdiv($index, 256), $index % 256),
        ];
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

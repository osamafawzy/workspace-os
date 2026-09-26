<?php

namespace Modules\Workspace\Actions;

use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Workspace\FloorMap\FloorObjectTypes;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;
use Modules\Workspace\Models\Workstation;

/**
 * Puts every desk that is not on a floor's map onto it in a grid, leaving
 * desks already on the map exactly where they are.
 *
 * This is the starting point, not the answer: a real room is not a grid. The
 * point is to get every desk onto the map in one go, so the work becomes
 * dragging some of them into place rather than placing all of them — which at
 * three hundred desks is the difference between an afternoon and a minute.
 *
 * Used where desks arrive in bulk on the server — "Add many" and the demo
 * seeder. In the map editor the same arranging happens in the browser, as part
 * of the unsaved draft, so it can be undone.
 */
class ArrangeWorkstations
{
    /** @return int how many desks were placed */
    public function handle(Floor $floor): int
    {
        $loose = $floor->workstations()->unplaced()->orderBy('name')->get();

        if ($loose->isEmpty()) {
            return 0;
        }

        // Shaped to the room, not to a fixed ratio. A grid laid out as if every
        // floor were 3:2 puts desks shoulder to shoulder down one axis and
        // metres apart on the other the moment a floor is long and narrow.
        $columns = max(1, (int) round(sqrt($loose->count() * $floor->aspectRatio())));
        $rows = (int) ceil($loose->count() / $columns);

        // Inset from the edges: desks against the wall are hard to grab, and no
        // real room uses its perimeter that way. A big floor does not need a
        // proportionally big margin — 10% of a 60 m floor is a six-metre
        // corridor of nothing — so it shrinks as the room grows.
        $margin = $floor->width_m >= 30 ? 5.0 : 10.0;
        $span = 100.0 - ($margin * 2);

        $positions = [];

        foreach ($loose as $index => $desk) {
            $column = $index % $columns;
            $row = intdiv($index, $columns);

            $positions[$desk->getKey()] = [
                $margin + ($columns > 1 ? $span * $column / ($columns - 1) : $span / 2),
                $margin + ($rows > 1 ? $span * $row / ($rows - 1) : $span / 2),
            ];
        }

        $this->write($floor, $positions);

        return count($positions);
    }

    /**
     * Lay desks into one rectangle of the floor.
     *
     * This is how a floor with a real drawing behind it gets filled in. The
     * desk banks on an architect's plan are rectangles; drawing a box round one
     * and saying "eight across, three down" is the same work as placing
     * twenty-four desks by hand, without the twenty-four drags.
     *
     * Desks come off the tray in name order, so a zone ends up holding a
     * contiguous run — A-001 to A-024 — rather than a scatter of whatever
     * happened to be left over.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  x0, y0, x1, y1 as percentages of the floor
     * @return Collection<int, Workstation> the desks that were placed
     */
    public function fill(Floor $floor, array $box, int $columns, int $rows): Collection
    {
        [$x0, $y0, $x1, $y1] = $box;

        /** @var Collection<int, Workstation> $loose */
        $loose = $floor->workstations()
            ->unplaced()
            ->orderBy('name')
            ->take($columns * $rows)
            ->get();

        if ($loose->isEmpty()) {
            return $loose;
        }

        $positions = [];

        foreach ($loose as $index => $desk) {
            $column = $index % $columns;
            $row = intdiv($index, $columns);

            // Spread to the edges of the box rather than inset from them: the
            // box was drawn round a bank of desks, so its edges are where the
            // outermost desks go.
            $positions[$desk->getKey()] = [
                $columns > 1 ? $x0 + (($x1 - $x0) * $column / ($columns - 1)) : ($x0 + $x1) / 2,
                $rows > 1 ? $y0 + (($y1 - $y0) * $row / ($rows - 1)) : ($y0 + $y1) / 2,
            ];
        }

        $this->write($floor, $positions);

        return $loose;
    }

    /**
     * One insert for all of them, and one audit entry.
     *
     * @param  array<int, array{0: float, 1: float}>  $positions  desk id => [x, y] as percentages of the floor
     */
    public function write(Floor $floor, array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $type = app(FloorObjectTypes::class)->get('workstation');
        $now = now();
        $rows = [];

        foreach ($positions as $id => [$x, $y]) {
            $rows[] = [
                'floor_id' => $floor->getKey(),
                'type' => 'workstation',
                'workstation_id' => $id,
                'x' => round(max(0, min(100, $x)) / 100 * $floor->width_m, 3),
                'y' => round(max(0, min(100, $y)) / 100 * $floor->depth_m, 3),
                'z' => 0,
                'width' => $type->width,
                'depth' => $type->depth,
                'height' => $type->height,
                'rotation' => 0,
                'locked' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($floor, $rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                FloorObject::query()->insert($chunk);
            }

            // An editor open on this floor must not save over what just arrived.
            $floor->newQuery()->whereKey($floor->getKey())->increment('map_revision');
        });

        app(AuditLogger::class)->log('arranged', 'Workspace', $floor, [], [
            'desks placed' => count($rows),
        ], $floor->name);
    }
}

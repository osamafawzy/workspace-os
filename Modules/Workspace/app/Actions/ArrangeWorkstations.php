<?php

namespace Modules\Workspace\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

/**
 * Lays every unplaced desk on a floor out in a grid, leaving already-placed
 * desks exactly where they are.
 *
 * This is the starting point, not the answer: a real room is not a grid. The
 * point is to get every desk onto the plan in one go, so the work becomes
 * dragging some of them into place rather than placing all of them — which at
 * three hundred desks is the difference between an afternoon and a minute.
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
                round($margin + ($columns > 1 ? $span * $column / ($columns - 1) : $span / 2), 2),
                round($margin + ($rows > 1 ? $span * $row / ($rows - 1) : $span / 2), 2),
            ];
        }

        $this->write($floor, $positions);

        return count($positions);
    }

    /**
     * Lay desks into one rectangle of the plan.
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
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  x0, y0, x1, y1 as percentages of the plan
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
                round($columns > 1 ? $x0 + (($x1 - $x0) * $column / ($columns - 1)) : ($x0 + $x1) / 2, 2),
                round($rows > 1 ? $y0 + (($y1 - $y0) * $row / ($rows - 1)) : ($y0 + $y1) / 2, 2),
            ];
        }

        $this->write($floor, $positions);

        return $loose;
    }

    /**
     * Three hundred individual updates, but inside one transaction rather than
     * three hundred separate commits — which is the part that actually costs
     * seconds. Hand-built CASE SQL would shave a little more off, and is not
     * worth writing coordinate values into a query string to get.
     *
     * @param  array<int, array{0: float, 1: float}>  $positions  desk id => [x, y]
     */
    public function write(Floor $floor, array $positions): void
    {
        if ($positions === []) {
            return;
        }

        DB::transaction(function () use ($floor, $positions): void {
            foreach ($positions as $id => [$x, $y]) {
                $floor->workstations()
                    ->whereKey($id)
                    ->update(['position_x' => $x, 'position_y' => $y]);
            }
        });
    }
}

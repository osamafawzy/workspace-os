<?php

namespace Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Workspace\FloorMap\FloorObjectTypes;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;

/**
 * Keeps a floor's map in step with a change to the floor's size.
 *
 * The usual reason a floor is resized is that its real size has just been set
 * from the drawing — after desks were already placed over that drawing. The
 * drawing stretches to the new size, so the map has to stretch with it or
 * every desk ends up off the bank it was placed on.
 *
 * Every object's position scales. Walls and rooms — the types that describe
 * the building rather than sit in it — scale their size too; a desk stays a
 * 1.4 m desk.
 */
class ScaleFloorMap
{
    public function handle(Floor $floor, float $oldWidth, float $oldDepth): void
    {
        if ($oldWidth <= 0 || $oldDepth <= 0) {
            return;
        }

        $sx = $floor->width_m / $oldWidth;
        $sy = $floor->depth_m / $oldDepth;

        if (abs($sx - 1) < 1e-9 && abs($sy - 1) < 1e-9) {
            return;
        }

        $types = app(FloorObjectTypes::class);

        DB::transaction(function () use ($floor, $sx, $sy, $types): void {
            $floor->mapObjects()->lazyById(500)->each(function (FloorObject $object) use ($sx, $sy, $types): void {
                $values = [
                    'x' => round($object->x * $sx, 3),
                    'y' => round($object->y * $sy, 3),
                ];

                if ($types->get($object->type)?->scalesWithFloor) {
                    // An object turned side-on has its width along the map's
                    // y axis, so it takes the other axis's factor.
                    $sideways = abs(sin(deg2rad($object->rotation))) > M_SQRT1_2;

                    $values['width'] = round($object->width * ($sideways ? $sy : $sx), 3);
                    $values['depth'] = round($object->depth * ($sideways ? $sx : $sy), 3);
                }

                $object->update($values);
            });

            $floor->newQuery()->whereKey($floor->getKey())->increment('map_revision');
        });
    }
}

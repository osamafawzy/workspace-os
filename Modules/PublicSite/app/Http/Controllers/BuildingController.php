<?php

namespace Modules\PublicSite\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\PublicSite\Support\BuildingGeometry;
use Modules\Workspace\Models\Floor;

class BuildingController extends Controller
{
    /** The building: every active floor, stacked, in 3D. */
    public function index(): View
    {
        $floors = BuildingGeometry::floors();

        return view('publicsite::building', [
            'floors' => $floors,
            'scene' => BuildingGeometry::forScene($floors),
        ]);
    }

    /**
     * One floor, flat.
     *
     * This is where the 3D view sends you for detail, and it is also what
     * somebody without WebGL gets — so it has to stand on its own as a plain
     * page rather than being a companion to the canvas.
     */
    public function floor(Floor $floor): View
    {
        abort_unless($floor->is_active, 404);

        $desks = $floor->workstations()->orderBy('name')->get();

        return view('publicsite::floor', [
            'floor' => $floor,
            'desks' => $desks,
            'placed' => BuildingGeometry::desks($floor),
            // Every desk, placed or not: the plan can only show the placed
            // ones, but the list beside it shows them all and each row opens
            // the same modal.
            'deskData' => BuildingGeometry::describe($desks, $floor),
            'planImage' => $floor->hasPlan()
                ? Storage::disk('public')->url($floor->plan_path)
                : null,
            'otherFloors' => BuildingGeometry::floors()->reject(
                fn (Floor $other): bool => $other->is($floor),
            ),
        ]);
    }
}

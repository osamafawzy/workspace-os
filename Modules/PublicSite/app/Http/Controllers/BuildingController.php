<?php

namespace Modules\PublicSite\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\PublicSite\Support\BuildingGeometry;
use Modules\Workspace\Models\Floor;

class BuildingController extends Controller
{
    /**
     * A building: every active floor, stacked, in 3D.
     *
     * The first building unless another is asked for by `?building=`. With
     * only one building nobody ever sees the choice.
     */
    public function index(Request $request): View
    {
        $buildings = BuildingGeometry::buildings();

        $building = $request->filled('building')
            ? $buildings->firstWhere('id', (int) $request->query('building')) ?? abort(404)
            : $buildings->first();

        $floors = $building ? BuildingGeometry::floors($building) : collect();

        return view('publicsite::building', [
            'building' => $building,
            'buildings' => $buildings,
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

        $desks = $floor->workstations()->with(['mapObject', 'floor'])->orderBy('name')->get();

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
            'building' => $floor->building,
            'buildings' => BuildingGeometry::buildings(),
            'otherFloors' => BuildingGeometry::floors($floor->building)->reject(
                fn (Floor $other): bool => $other->is($floor),
            ),
        ]);
    }
}

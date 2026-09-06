<?php

namespace Modules\PublicSite\Support;

use Illuminate\Support\Collection;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;

/**
 * Turns the floors and their desks into the shape the 3D scene wants.
 *
 * The renderer should not know about Eloquent, and the models should not know
 * about metres. This is the one place the translation happens: percentages and
 * storey numbers in, a plain array of floors and desk coordinates out.
 */
class BuildingGeometry
{
    /**
     * Encodes a payload for a <script type="application/json"> block.
     *
     * Blade's @json calls json_encode with no flags, and json_encode leaves
     * `<` and `>` alone. Desk names and notes are typed by an admin, so a note
     * containing a closing script tag would end the block early and whatever
     * followed it would run as script on a page every visitor can open.
     * JSON_HEX_TAG escapes both characters to their \u00XX form, which is
     * still valid JSON and cannot close anything.
     *
     * Slashes are left alone because escaping them is only ever a partial
     * version of the same protection, and `Gi1\/0\/24` in the payload is
     * needlessly hard to read when something goes wrong.
     */
    public static function json(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Inactive floors are left out. A floor switched off is one the building
     * no longer uses, and a public viewer walking in should not be sent to a
     * storey nobody works on.
     *
     * @return Collection<int, Floor>
     */
    public static function floors(): Collection
    {
        return Floor::query()
            ->active()
            ->inBuildingOrder()
            ->withCount([
                'workstations',
                'workstations as placed_workstations_count' => fn ($query) => $query->placed(),
            ])
            ->get();
    }

    /**
     * @param  Collection<int, Floor>  $floors
     * @return array<string, mixed>
     */
    public static function forScene(Collection $floors): array
    {
        return [
            'name' => config('app.name'),
            'floors' => $floors->map(fn (Floor $floor): array => [
                'id' => $floor->getKey(),
                'name' => $floor->name,
                'level' => $floor->level,
                // Real metres. The scene draws each slab at its own size, so a
                // 300-desk floor plate and a small mezzanine in the same
                // building come out looking like what they are.
                'width' => $floor->width_m,
                'depth' => $floor->depth_m,
                'url' => route('building.floor', $floor),
                'deskCount' => (int) $floor->workstations_count,
                'placedCount' => (int) $floor->placed_workstations_count,
                // Only desks with coordinates go into the scene — an unplaced
                // desk has no position to draw it at, and guessing one would
                // put a desk in the room that is not there.
                'desks' => self::desks($floor),
            ])->values()->all(),
        ];
    }

    /**
     * The placed desks on a floor, ready to draw and ready to open.
     *
     * @return list<array<string, mixed>>
     */
    public static function desks(Floor $floor): array
    {
        return self::describe(
            $floor->workstations()->placed()->orderBy('name')->get(),
            $floor,
        );
    }

    /**
     * One desk as the browser reads it: where it is, and what is recorded
     * about it, already grouped and labelled.
     *
     * The grouping is done here rather than in JavaScript so the floor page,
     * the 3D scene and the admin form all take their field names from the same
     * declaration on the model. The renderer stays a renderer.
     *
     * @param  Collection<int, Workstation>  $desks
     * @return list<array<string, mixed>>
     */
    public static function describe(Collection $desks, ?Floor $floor = null): array
    {
        return $desks->map(fn (Workstation $desk): array => [
            'id' => $desk->getKey(),
            'name' => $desk->name,
            'floor' => ($floor ?? $desk->floor)?->name,
            // Null for a desk that has not been put on the plan yet. It still
            // gets a modal — it exists, it just has no dot to click.
            'x' => $desk->position_x !== null ? (float) $desk->position_x : null,
            'y' => $desk->position_y !== null ? (float) $desk->position_y : null,
            'groups' => $desk->detailGroups(),
        ])->values()->all();
    }
}

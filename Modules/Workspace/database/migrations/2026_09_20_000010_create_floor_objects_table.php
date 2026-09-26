<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything drawn on a floor's map, as objects with a real size and a real
 * position: desks, walls, rooms, doors, racks, printers, signs.
 *
 * Until now a desk's place on the plan was a percentage pair on the desk
 * itself, and there was nothing else on the plan. Every placed desk becomes a
 * workstation object here, at the same spot, now in metres, and the old
 * percentage columns go.
 *
 * Positions and sizes are metres from the floor's top-left corner, with the
 * object's centre at (x, y), `width` along its own x axis, `depth` along its
 * own y axis, `height` upwards from `z`, and `rotation` clockwise in degrees.
 * Rotation 0 faces "up" the map: a workstation's monitor is at the top edge
 * and its chair below.
 */
return new class extends Migration
{
    /** The footprint of a desk with its chair, in metres. */
    private const DESK_WIDTH = 1.4;

    private const DESK_DEPTH = 1.5;

    private const DESK_HEIGHT = 0.75;

    public function up(): void
    {
        Schema::create('floor_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();

            // The object type key from the registry: workstation, wall, door...
            $table->string('type', 40);

            // A workstation object is the desk record's place on the map. One
            // desk, one place; deleting the desk takes its map object with it.
            $table->foreignId('workstation_id')->nullable()->unique()->constrained()->cascadeOnDelete();

            $table->string('label', 100)->nullable();

            $table->decimal('x', 9, 3);
            $table->decimal('y', 9, 3);
            $table->decimal('z', 7, 3)->default(0);
            $table->decimal('width', 8, 3);
            $table->decimal('depth', 8, 3);
            $table->decimal('height', 7, 3)->default(0);
            $table->decimal('rotation', 6, 2)->default(0);

            // Type-specific settings: a colour, a door's swing, a sign's text.
            $table->json('props')->nullable();
            $table->boolean('locked')->default(false);

            $table->timestamps();

            $table->index(['floor_id', 'type']);
        });

        // Bumped on every save of the map, so an editor open in two tabs cannot
        // silently overwrite the other's changes.
        Schema::table('floors', function (Blueprint $table) {
            $table->unsignedInteger('map_revision')->default(0)->after('plan_path');
        });

        $this->movePlacementsIntoObjects();

        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn(['position_x', 'position_y']);
        });
    }

    protected function movePlacementsIntoObjects(): void
    {
        $floors = DB::table('floors')->get(['id', 'width_m', 'depth_m'])->keyBy('id');
        $now = now();

        DB::table('workstations')
            ->whereNotNull('position_x')
            ->whereNotNull('position_y')
            ->orderBy('id')
            ->chunkById(500, function ($desks) use ($floors, $now): void {
                $rows = [];

                foreach ($desks as $desk) {
                    $floor = $floors[$desk->floor_id];

                    $rows[] = [
                        'floor_id' => $desk->floor_id,
                        'type' => 'workstation',
                        'workstation_id' => $desk->id,
                        'x' => round((float) $desk->position_x / 100 * (float) $floor->width_m, 3),
                        'y' => round((float) $desk->position_y / 100 * (float) $floor->depth_m, 3),
                        'z' => 0,
                        'width' => self::DESK_WIDTH,
                        'depth' => self::DESK_DEPTH,
                        'height' => self::DESK_HEIGHT,
                        'rotation' => 0,
                        'locked' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('floor_objects')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->decimal('position_x', 5, 2)->nullable();
            $table->decimal('position_y', 5, 2)->nullable();
        });

        $floors = DB::table('floors')->get(['id', 'width_m', 'depth_m'])->keyBy('id');

        foreach (DB::table('floor_objects')->where('type', 'workstation')->whereNotNull('workstation_id')->get() as $object) {
            $floor = $floors[$object->floor_id];

            DB::table('workstations')->where('id', $object->workstation_id)->update([
                'position_x' => round(max(0, min(100, (float) $object->x / max((float) $floor->width_m, 1) * 100)), 2),
                'position_y' => round(max(0, min(100, (float) $object->y / max((float) $floor->depth_m, 1) * 100)), 2),
            ]);
        }

        Schema::table('floors', function (Blueprint $table) {
            $table->dropColumn('map_revision');
        });

        Schema::dropIfExists('floor_objects');
    }
};

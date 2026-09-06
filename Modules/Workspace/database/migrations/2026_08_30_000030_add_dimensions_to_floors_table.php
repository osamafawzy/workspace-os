<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            // How big the floor actually is, in metres.
            //
            // Desk coordinates stay percentages — the drawing is still the
            // coordinate space, and nothing already placed moves when a floor
            // is resized. These are what everything that *draws* the floor
            // needs: the plan takes its shape from the ratio, and the 3D view
            // needs real dimensions or a 300-desk floor comes out as a pile of
            // overlapping blocks on a room the size of a meeting table.
            //
            // 60 x 40 is 2,400 m2 — a large open-plan floor, roughly 300 desks
            // at 8 m2 each including circulation. It is the default because a
            // floor whose size nobody has stated is far more likely to be a big
            // one than a 3 x 2 cupboard.
            $table->decimal('width_m', 7, 2)->default(60)->after('level');
            $table->decimal('depth_m', 7, 2)->default(40)->after('width_m');
        });
    }

    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropColumn(['width_m', 'depth_m']);
        });
    }
};

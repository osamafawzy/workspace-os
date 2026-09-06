<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstations', function (Blueprint $table) {
            $table->id();

            // A workstation is a physical desk on a physical floor. It cannot
            // outlive the floor it sits on, so the delete cascades rather than
            // leaving desks pointing at a building that is gone.
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);

            // Where the desk sits on the floor plan, as a percentage of the
            // plan's width and height rather than pixels. The drawings are not
            // here yet and will not all be the same size when they are, so
            // percentages survive a plan being re-exported at another
            // resolution — pixel offsets would not.
            //
            // Null means "we know this desk exists, we have not placed it on
            // the plan yet", which is every desk today.
            $table->decimal('position_x', 5, 2)->nullable();
            $table->decimal('position_y', 5, 2)->nullable();

            $table->timestamps();

            // Two desks on one floor cannot share a name — "A-12" has to mean
            // one desk when somebody says it out loud. The same name on
            // another floor is fine.
            $table->unique(['floor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstations');
    }
};

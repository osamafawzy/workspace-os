<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // A floor's position in the building, not a display order: 0 is
            // ground, 1 is the floor above it, -1 a basement. Two floors
            // cannot occupy the same level, and sorting by it walks the
            // building bottom to top the way a person would.
            $table->smallInteger('level');

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            // The scanned or exported floor plan this floor's workstations are
            // positioned against. Empty until the drawings arrive; a floor
            // without one still holds workstations, they just have no
            // coordinates yet.
            $table->string('plan_path')->nullable();

            $table->timestamps();

            $table->unique('name');
            $table->unique('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};

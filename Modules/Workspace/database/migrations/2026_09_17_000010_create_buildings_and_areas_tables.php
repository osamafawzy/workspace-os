<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The physical hierarchy: a site has buildings, a building has floors, a floor
 * is divided into areas.
 *
 *   Alexandria Site → HQ Tower B → Floor 2 → Operations Floor
 *
 * Sites are a Settings lookup (they are also where assets and employees are),
 * so this adds the two levels that only matter to floors: the building a floor
 * is in, and the areas it is split into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            // A site cannot be deleted from under its buildings.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            // An area is a part of one floor and goes with it.
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // "Zone A" on two floors is two different areas.
            $table->unique(['floor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
        Schema::dropIfExists('buildings');
    }
};

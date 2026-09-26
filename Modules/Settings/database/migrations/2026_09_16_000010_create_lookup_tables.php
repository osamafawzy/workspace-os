<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lists every later module picks from: where things are (sites and the
 * locations within them), who they are for (accounts), and which part of the
 * company (departments).
 *
 * Real tables with foreign keys rather than one generic "lookups" table,
 * because an asset or an employee will point at a site with a constraint, and
 * a constraint needs a table of its own to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->string('city', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            // Deleting a site is refused while locations still point at it,
            // rather than quietly leaving them siteless.
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('sites');
    }
};

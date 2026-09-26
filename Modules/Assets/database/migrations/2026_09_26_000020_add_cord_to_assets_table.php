<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cord that comes with a headset.
 *
 * The ops sheet records a headset and its cord on one line — the cord has its
 * own model, serial and state, but it lives and dies with the headset it came
 * with — so the cord is kept on the headset's own record rather than as a
 * second asset. Any asset may carry them; only headsets normally do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('cord_model', 100)->nullable()->after('notes');
            $table->string('cord_serial', 100)->nullable()->after('cord_model');
            $table->string('cord_condition', 20)->nullable()->after('cord_serial');

            $table->index('cord_serial');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['cord_serial']);
            $table->dropColumn(['cord_model', 'cord_serial', 'cord_condition']);
        });
    }
};

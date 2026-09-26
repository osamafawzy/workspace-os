<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Release Data Form carries beyond the labels.
 *
 * The office fills a release in on one sheet — the laptop's memory, who owns
 * it, the line of business it is for, the day it is handed over — and those
 * belong to the delivery rather than to the asset that comes out of it, so they
 * are kept on the batch's own row and printed with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_batch_items', function (Blueprint $table) {
            $table->string('ram', 60)->nullable()->after('computer_name');
            $table->string('owner', 60)->nullable()->after('ram');
            $table->string('lob', 100)->nullable()->after('owner');
            $table->date('delivery_date')->nullable()->after('lob');
        });
    }

    public function down(): void
    {
        Schema::table('release_batch_items', function (Blueprint $table) {
            $table->dropColumn(['ram', 'owner', 'lob', 'delivery_date']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an asset was changed: "replaced — faulty", "lost", "upgrade"…
 *
 * A managed list, so the reasons are the company's own and can be added to or
 * retired from Settings without touching code. The reason is recorded on the
 * asset's history entry: the id, so the list can be counted and filtered, and
 * the name as it read at the time, so a later rename does not rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_update_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('asset_history', function (Blueprint $table) {
            $table->foreignId('asset_update_reason_id')->nullable()->after('changes')->constrained()->nullOnDelete();
            $table->string('reason', 100)->nullable()->after('asset_update_reason_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_update_reason_id');
            $table->dropColumn('reason');
        });

        Schema::dropIfExists('asset_update_reasons');
    }
};

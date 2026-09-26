<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releasing new assets: a delivery of new kit, staged before it enters the
 * register and goes out to people.
 *
 * A batch is the "new data": what the delivery is (type and model), where it
 * lands (site, location, account), and one item per piece — its serial, tag,
 * computer name, and the OID of whoever it is going to. Items are checked
 * against the employees, against each other and against the existing assets
 * while the batch is a draft; releasing it creates the assets, hands each to
 * its employee with a form, and archives the batch so it can be reprinted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_batches', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->text('notes')->nullable();
            // The last time each check ran: employees, new data, old data.
            $table->json('checks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('released_by_name')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamps();
        });

        Schema::create('release_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_batch_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number', 100);
            $table->string('asset_tag', 100)->nullable();
            $table->string('computer_name', 100)->nullable();
            $table->string('employee_oid', 50)->nullable();
            $table->string('condition', 20)->default('new');
            $table->text('notes')->nullable();
            // What the checks found: check => list of {level, text}.
            $table->json('findings')->nullable();
            // Filled in when the batch is released.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handover_form_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['release_batch_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_batch_items');
        Schema::dropIfExists('release_batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The asset register.
 *
 * The catalogue — manufacturers, asset types, and the models that are one
 * manufacturer's product of one type — is what an asset is. The asset is one
 * physical thing with a serial number: where it is, what state it is in, and
 * who holds it.
 *
 * `asset_history` is the asset's own story, told the way people ask about it
 * ("when did this laptop move to Floor 3, and who had it before?"). The audit
 * log records the same writes for the whole application; this is kept per
 * asset so the question is one query, and so it survives in a readable form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            // Headsets are assets like any other; the type says which ones
            // are, so the headset screens and reports can find them.
            $table->boolean('is_headset')->default(false);
            // Laptops and desktops have a computer name; monitors do not.
            $table->boolean('has_computer_name')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['manufacturer_id', 'name']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('serial_number', 100)->unique();
            $table->string('asset_tag', 100)->nullable()->unique();
            $table->string('computer_name', 100)->nullable()->index();
            $table->string('status', 20)->default('available')->index();
            $table->string('condition', 20)->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            // The employee holding it now. An employee holding assets cannot
            // be deleted; they hand them back first.
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expires_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            // field => {from, to}, as they were shown: names, not ids.
            $table->json('changes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['asset_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_history');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_models');
        Schema::dropIfExists('asset_types');
        Schema::dropIfExists('manufacturers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Handing assets over and taking them back.
 *
 * `asset_assignments` is the ledger: one row per spell an asset spends with an
 * employee, opened when it is handed over and closed when it comes back. An
 * asset has at most one open row, and it is the one its `employee_id` names.
 *
 * `asset_returns` is what was recorded when one came back — the date, the
 * condition, who brought it and who received it — searchable on its own.
 *
 * `handover_forms` are the printed papers: a handover form when assets go out,
 * a return receipt when they come back. Each keeps a frozen, encrypted copy of
 * what it said, so a reprint years later is the same paper — even after the
 * employee's department or the asset's tag has changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_forms', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->string('kind', 20)->index();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            // Names, contacts and assets as printed. Encrypted: it holds the
            // employee's emergency contacts.
            $table->longText('snapshot');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('generated_by_name')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('handover_form_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_by_name')->nullable();
            $table->string('condition_out', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'returned_at']);
            $table->index(['employee_id', 'returned_at']);
        });

        Schema::create('asset_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_assignment_id')->unique()->constrained()->cascadeOnDelete();
            // Copied from the assignment so the returned-assets search does
            // not have to go through it for its most common filters.
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('handover_form_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('returned_at')->index();
            $table->string('condition', 20);
            // Who physically brought it back: usually the employee, sometimes
            // their team leader.
            $table->string('returned_by_name', 150)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Assets already with somebody before assignments were recorded get
        // an open spell, so they can be returned like any other.
        $now = now();

        DB::table('assets')->whereNotNull('employee_id')->orderBy('id')->each(function (object $asset) use ($now): void {
            DB::table('asset_assignments')->insert([
                'asset_id' => $asset->id,
                'employee_id' => $asset->employee_id,
                'assigned_at' => $asset->assigned_at ?? $asset->updated_at ?? $now,
                'assigned_by_name' => 'Recorded before assignments',
                'condition_out' => $asset->condition,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_returns');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('handover_forms');
    }
};

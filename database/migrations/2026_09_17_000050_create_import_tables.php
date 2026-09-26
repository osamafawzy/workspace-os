<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spreadsheet imports, held between "check this file" and "import it".
 *
 * Every row is checked and stored here first, so the preview is a real
 * account of what will happen — which rows are new, which already exist,
 * which are duplicates or broken — and nothing reaches the real tables until
 * somebody has seen that and said go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Which importer: "workstations", later "assets", "employees"...
            $table->string('importer', 50);
            $table->string('file_name');
            // checked → imported, or checked → cancelled.
            $table->string('status', 20)->default('checked');
            $table->json('options')->nullable();
            // Counts from the check, then from the import.
            $table->json('summary')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['importer', 'created_at']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            // The line in the spreadsheet, so a problem can be found there.
            $table->unsignedInteger('row_number');
            // The values as read, keyed by field.
            $table->json('data');
            // new, update, unchanged, duplicate, invalid; then imported,
            // updated, skipped, failed.
            $table->string('status', 20);
            $table->json('messages')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};

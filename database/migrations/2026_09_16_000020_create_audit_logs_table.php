<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who. The name is copied at the time so the log still reads
            // properly after the account is renamed or deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            // What: "created", "updated", "moved", "assigned"...
            $table->string('action', 50);

            // Where in the application, e.g. Workspace, Access, Assets.
            $table->string('module', 50);

            // Which record, if the entry is about one. The label is copied
            // for the same reason as the user name: a deleted desk still has
            // to be recognisable in its own deletion entry.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('record_label')->nullable();

            // Only the fields that changed, before and after.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('hostname')->nullable();
            $table->string('user_agent')->nullable();

            // Append-only: an entry is never edited, so there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('module');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

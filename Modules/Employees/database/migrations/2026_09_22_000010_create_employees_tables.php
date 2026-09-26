<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees, and the two people to call for each of them.
 *
 * The OID is the identifier everything else uses — the Workday export, asset
 * forms, the legacy application — so it is the unique key an import matches
 * on.
 *
 * The national ID is stored encrypted, which means the database cannot search
 * or compare it. `national_id_hash` is a keyed hash of it (an HMAC with the
 * application key) so that the same ID on two employees can still be noticed
 * without the ID itself being readable from a database dump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('oid', 50)->unique();
            $table->string('employee_number', 50)->nullable()->unique();
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->text('national_id')->nullable();
            $table->string('national_id_hash', 64)->nullable()->index();
            $table->text('address')->nullable();
            // A department, account, site or location still in use by an
            // employee cannot be deleted from Settings; retire it instead.
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('job_title', 150)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // 1 is who to call first, 2 who to call if they do not answer.
            $table->unsignedTinyInteger('slot');
            $table->string('name', 150);
            $table->string('relationship', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('employees');
    }
};

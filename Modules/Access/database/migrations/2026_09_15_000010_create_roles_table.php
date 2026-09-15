<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100)->unique();
            $table->text('description')->nullable();

            // Bypasses every permission check rather than holding every
            // permission, so a permission added later is covered without
            // anybody having to remember to tick it.
            $table->boolean('is_super_admin')->default(false);

            // The permission keys this role was given. The catalogue itself
            // lives in code (App\Support\Permissions), because a permission
            // only means something where a policy checks it.
            $table->json('permissions')->nullable();

            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Until this module existed every account was a full administrator, and the
 * panel now only admits users who hold a role. Without this step, deploying
 * roles would lock every existing account out of the panel on the spot.
 *
 * So everyone who could do everything before keeps being able to: a Super
 * Admin role is created and handed to each existing user. Narrow them down
 * from the Users screen afterwards.
 *
 * Query builder rather than models, so this keeps running the same way however
 * the models change later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roleId = DB::table('roles')->where('is_super_admin', true)->orderBy('id')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Super Admin',
                'description' => 'Full access to everything, including permissions added later.',
                'is_super_admin' => true,
                'permissions' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach (DB::table('users')->orderBy('id')->pluck('id') as $userId) {
            DB::table('role_user')->insertOrIgnore([
                'role_id' => $roleId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo separately: rolling back the table migration drops
        // the role and every assignment with it.
    }
};

<?php

namespace Modules\Access\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Role;
use Tests\TestCase;

class SuperAdminRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_makes_an_account_super_admin(): void
    {
        $user = User::factory()->create(['email' => 'locked@workspace.test']);

        $this->artisan('access:grant-super-admin', ['email' => 'locked@workspace.test'])
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->isSuperAdmin());
    }

    public function test_the_command_says_so_when_there_is_no_such_account(): void
    {
        $this->artisan('access:grant-super-admin', ['email' => 'nobody@workspace.test'])
            ->assertFailed();
    }

    public function test_running_it_twice_does_not_make_a_second_role(): void
    {
        User::factory()->create(['email' => 'a@workspace.test']);

        $this->artisan('access:grant-super-admin', ['email' => 'a@workspace.test']);
        $this->artisan('access:grant-super-admin', ['email' => 'a@workspace.test']);

        $this->assertSame(1, Role::query()->where('is_super_admin', true)->count());
    }

    /**
     * Deploying roles onto a database that already has accounts must not
     * lock them out: everyone who could do everything before still can.
     */
    public function test_the_upgrade_migration_keeps_existing_accounts_in(): void
    {
        $existing = User::factory()->count(2)->create();
        DB::table('role_user')->delete();
        DB::table('roles')->delete();

        $migration = require base_path('Modules/Access/database/migrations/2026_09_15_000020_grant_existing_users_super_admin.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, Role::query()->where('is_super_admin', true)->count());

        foreach ($existing as $user) {
            $this->assertTrue($user->refresh()->isSuperAdmin());
        }
    }
}

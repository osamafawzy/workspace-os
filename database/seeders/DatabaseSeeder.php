<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Database\Seeders\AccessDatabaseSeeder;
use Modules\Access\Models\Role;
use Modules\Assets\Database\Seeders\AssetsDatabaseSeeder;
use Modules\Employees\Database\Seeders\EmployeesDatabaseSeeder;
use Modules\Settings\Database\Seeders\SettingsDatabaseSeeder;
use Modules\Workspace\Database\Seeders\WorkspaceDatabaseSeeder;

/**
 * A complete demo: logins for every role, the Settings lists, a patched
 * building with its floor maps, ninety employees, and an asset register with a
 * year of handovers, returns, releases and imports behind it.
 *
 * Model events stay on. The things a real install records as it goes — asset
 * history, the assignment ledger, form and batch numbers, the audit log — are
 * written by those events, and a demo without them would show empty screens.
 *
 * Every seeder is idempotent: re-running `php artisan db:seed` adds what is
 * missing and changes nothing that is already there.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // A known login for the admin panel. updateOrCreate so re-seeding a
        // working database does not fail on the unique email.
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@workspace.test'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        // The seeded login holds the super admin role, so it can reach every
        // screen — including the ones for handing out narrower roles.
        $admin->roles()->syncWithoutDetaching([Role::ensureSuperAdmin()->getKey()]);

        // What the seeders do is done "by" the admin, as it would be in the
        // panel: the audit log, the asset history and the forms name somebody.
        auth()->setUser($admin);

        $this->call([
            AccessDatabaseSeeder::class,
            SettingsDatabaseSeeder::class,
            WorkspaceDatabaseSeeder::class,
            EmployeesDatabaseSeeder::class,
            AssetsDatabaseSeeder::class,
        ]);
    }
}

<?php

namespace Modules\Access\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Models\Role;
use Modules\Access\Support\DefaultRoles;

/**
 * The five default roles, and one demo login for each of the narrower ones so
 * what each role sees can be tried without editing anybody's permissions.
 *
 * The demo logins are never made on a production install: a known password on
 * a real server is a way in.
 */
class AccessDatabaseSeeder extends Seeder
{
    /** email => [name, role] */
    public const DEMO_USERS = [
        'itadmin@workspace.test' => ['Iman IT Admin', 'IT Admin'],
        'engineer@workspace.test' => ['Karim Engineer', 'IT Engineer'],
        'technician@workspace.test' => ['Tarek Technician', 'IT Technician'],
        'viewer@workspace.test' => ['Vera Viewer', 'Viewer'],
    ];

    public function run(): void
    {
        DefaultRoles::ensure();
        DefaultRoles::sync();

        if (app()->isProduction()) {
            return;
        }

        foreach (self::DEMO_USERS as $email => [$name, $roleName]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );

            $role = Role::query()->where('name', $roleName)->first();

            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->getKey()]);
            }
        }
    }
}

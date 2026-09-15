<?php

namespace Modules\Access\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Access\Models\Role;

/**
 * The way back in.
 *
 * Every screen guards against removing the last super admin, but a database
 * restored from somewhere else, or an account deleted by hand, can still leave
 * nobody able to open the panel. This is the fix that does not need the panel.
 */
class GrantSuperAdmin extends Command
{
    protected $signature = 'access:grant-super-admin {email : The account to make a super admin}';

    protected $description = 'Give an existing user the Super Admin role';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with the email '.$this->argument('email').'.');

            return self::FAILURE;
        }

        $role = Role::ensureSuperAdmin();
        $user->roles()->syncWithoutDetaching([$role->getKey()]);

        $this->info("{$user->email} now holds the {$role->name} role.");

        return self::SUCCESS;
    }
}

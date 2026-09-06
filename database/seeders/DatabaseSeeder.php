<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Workspace\Database\Seeders\WorkspaceDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // A known login for the admin panel. updateOrCreate so re-seeding a
        // working database does not fail on the unique email.
        User::query()->updateOrCreate(
            ['email' => 'admin@workspace.test'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            WorkspaceDatabaseSeeder::class,
        ]);
    }
}

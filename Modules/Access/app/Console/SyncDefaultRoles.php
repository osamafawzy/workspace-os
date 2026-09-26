<?php

namespace Modules\Access\Console;

use Illuminate\Console\Command;
use Modules\Access\Support\DefaultRoles;

/**
 * Run after a new module is deployed, so the default roles pick up the
 * permissions it registered. Only ever adds.
 */
class SyncDefaultRoles extends Command
{
    protected $signature = 'access:sync-default-roles';

    protected $description = 'Create any missing default roles and add newly registered permissions to them (never removes any)';

    public function handle(): int
    {
        foreach (DefaultRoles::ensure() as $name) {
            $this->info("Created role: {$name}");
        }

        $added = DefaultRoles::sync();

        if ($added === []) {
            $this->info('Default roles are already up to date.');

            return self::SUCCESS;
        }

        foreach ($added as $role => $permissions) {
            $this->line("{$role}: added ".implode(', ', $permissions));
        }

        return self::SUCCESS;
    }
}

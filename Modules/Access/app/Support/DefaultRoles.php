<?php

namespace Modules\Access\Support;

use App\Support\Audit\AuditLogger;
use App\Support\Permissions;
use Modules\Access\Models\Role;

/**
 * The five roles the IT team starts with.
 *
 * Written as patterns over the permission catalogue rather than as lists of
 * keys, so a module added later slots into the right roles by its naming alone:
 * a new `assets.view` is picked up by every role that grants `*.view`.
 *
 * These are only a starting point. Once a role exists it belongs to the people
 * running the panel: {@see ensure()} never edits an existing role, and
 * {@see sync()} only ever adds permissions a new module brought, never takes
 * one away that somebody removed on purpose.
 */
class DefaultRoles
{
    public const SUPER_ADMIN = Role::SUPER_ADMIN;

    /**
     * @return array<string, array{description: string, super?: bool, grant?: array<int, string>, deny?: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            self::SUPER_ADMIN => [
                'description' => 'Full access to everything, including permissions added later.',
                'super' => true,
            ],
            'IT Admin' => [
                'description' => 'Runs the application: everything except handing out super admin.',
                'grant' => ['*'],
            ],
            'IT Engineer' => [
                'description' => 'Builds and maintains floors, workstations and records. Cannot manage users, roles or settings.',
                'grant' => ['*.view', '*.create', '*.update', 'floors.arrange', '*.import', '*.export', '*.print', 'assignments.assign', 'assignments.return', 'releases.manage', 'releases.release'],
                'deny' => ['users.*', 'roles.*', 'settings.*', 'lookups.create', 'lookups.update'],
            ],
            'IT Technician' => [
                'description' => 'Works on the floor: finds workstations and assets, updates their details, arranges the plan.',
                'grant' => ['*.view', 'workstations.update', 'assets.update', 'assignments.assign', 'assignments.return', 'floors.arrange', '*.print'],
                'deny' => ['users.*', 'roles.*', 'settings.*', 'audit.*'],
            ],
            'Viewer' => [
                'description' => 'Can look at everything operational and change nothing.',
                'grant' => ['*.view'],
                'deny' => ['users.*', 'roles.*', 'settings.*', 'audit.*'],
            ],
        ];
    }

    /**
     * The permission keys a definition resolves to, against today's catalogue.
     *
     * @param  array{grant?: array<int, string>, deny?: array<int, string>}  $definition
     * @return array<int, string>
     */
    public static function resolve(array $definition): array
    {
        $matches = fn (string $key, array $patterns): bool => collect($patterns)
            ->contains(fn (string $pattern): bool => fnmatch($pattern, $key));

        return collect(app(Permissions::class)->keys())
            ->filter(fn (string $key): bool => $matches($key, $definition['grant'] ?? []))
            ->reject(fn (string $key): bool => $matches($key, $definition['deny'] ?? []))
            ->values()
            ->all();
    }

    /**
     * Create whichever default roles do not exist yet. Existing roles are left
     * exactly as they are.
     *
     * @return array<int, string> the names of the roles created
     */
    public static function ensure(): array
    {
        $created = [];

        foreach (self::definitions() as $name => $definition) {
            if (! empty($definition['super'])) {
                if (! Role::query()->where('is_super_admin', true)->exists()) {
                    Role::ensureSuperAdmin();
                    $created[] = $name;
                }

                continue;
            }

            if (Role::query()->where('name', $name)->exists()) {
                continue;
            }

            Role::query()->create([
                'name' => $name,
                'description' => $definition['description'],
                'is_super_admin' => false,
                'permissions' => self::resolve($definition),
            ]);

            $created[] = $name;
        }

        return $created;
    }

    /**
     * Add to each existing default role the permissions its pattern now
     * matches but it does not have — typically the ones a newly built module
     * registered. Never removes anything.
     *
     * @return array<string, array<int, string>> role name => permissions added
     */
    public static function sync(): array
    {
        $added = [];

        foreach (self::definitions() as $name => $definition) {
            if (! empty($definition['super'])) {
                continue;
            }

            $role = Role::query()->where('name', $name)->where('is_super_admin', false)->first();

            if (! $role) {
                continue;
            }

            $missing = array_values(array_diff(self::resolve($definition), $role->permissions ?? []));

            if ($missing === []) {
                continue;
            }

            $role->update(['permissions' => [...($role->permissions ?? []), ...$missing]]);
            $added[$name] = $missing;
        }

        if ($added !== []) {
            app(AuditLogger::class)->log('synced default roles', 'Access', null, [], $added, 'Default roles');
        }

        return $added;
    }
}

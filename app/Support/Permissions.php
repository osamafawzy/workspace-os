<?php

namespace App\Support;

/**
 * Every permission the application knows about, in the groups the role screen
 * shows them in.
 *
 * Permissions are declared in code, not typed into the database: a permission
 * only means something if a policy somewhere checks it, so one that exists as a
 * row but not in code is a checkbox that does nothing. Each module registers
 * its own from its service provider, and a role stores the keys it was given.
 */
class Permissions
{
    /** @var array<string, array<string, string>> group => [key => label] */
    protected array $groups = [];

    /**
     * @param  array<string, string>  $permissions  key => label
     */
    public function register(string $group, array $permissions): void
    {
        $this->groups[$group] = array_merge($this->groups[$group] ?? [], $permissions);
    }

    /** @return array<string, array<string, string>> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys(array_merge([], ...array_values($this->groups)));
    }

    public function exists(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /**
     * The given keys with anything unknown dropped, so a role can only ever
     * store permissions that some policy actually checks.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public function only(array $keys): array
    {
        return array_values(array_intersect($this->keys(), $keys));
    }
}

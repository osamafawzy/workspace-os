<?php

namespace App\Support\Navigation;

use App\Support\Settings;

/**
 * The sidebar: the defaults in config/navigation.php with whatever has been
 * changed on Settings → Navigation laid over the top.
 *
 * Groups are addressed by key everywhere — an item says it belongs to
 * "asset-management", not to "Asset Management" — so renaming a group from the
 * panel can never orphan the items inside it.
 */
class Navigation
{
    public const SETTING = 'navigation';

    public function __construct(protected Settings $settings) {}

    /**
     * @return array<string, array{label: string, icon: string|null, sort: int, collapsed: bool}>
     */
    public function groups(): array
    {
        $overrides = $this->overrides()['groups'] ?? [];
        $groups = [];

        foreach (config('navigation.groups', []) as $key => $defaults) {
            $override = array_filter($overrides[$key] ?? [], fn (mixed $value): bool => $value !== null && $value !== '');

            $groups[$key] = [
                'label' => (string) ($override['label'] ?? $defaults['label']),
                'icon' => $defaults['icon'] ?? null,
                'sort' => (int) ($override['sort'] ?? $defaults['sort'] ?? 0),
                'collapsed' => (bool) ($override['collapsed'] ?? $defaults['collapsed'] ?? false),
            ];
        }

        uasort($groups, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $groups;
    }

    /**
     * @return array<string, array{label: string, group: string|null, icon: string|null, sort: int, hidden: bool, phase: int|null, summary: string|null}>
     */
    public function items(): array
    {
        $items = [];

        foreach (array_keys(config('navigation.items', [])) as $key) {
            $items[$key] = $this->item($key);
        }

        return $items;
    }

    /**
     * @return array{label: string, group: string|null, icon: string|null, sort: int, hidden: bool, phase: int|null, summary: string|null}|null
     */
    public function item(string $key): ?array
    {
        $defaults = config("navigation.items.{$key}");

        if ($defaults === null) {
            return null;
        }

        $override = array_filter(
            $this->overrides()['items'][$key] ?? [],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $group = array_key_exists('group', $override) ? $override['group'] : ($defaults['group'] ?? null);

        // A group that has been removed from the config takes its items to
        // the top level rather than making them vanish.
        if ($group !== null && ! array_key_exists($group, config('navigation.groups', []))) {
            $group = null;
        }

        return [
            'label' => (string) ($override['label'] ?? $defaults['label']),
            'group' => $group,
            'icon' => $defaults['icon'] ?? 'heroicon-o-squares-2x2',
            'sort' => (int) ($override['sort'] ?? $defaults['sort'] ?? 0),
            'hidden' => (bool) ($override['hidden'] ?? false),
            'phase' => isset($defaults['phase']) ? (int) $defaults['phase'] : null,
            'summary' => $defaults['summary'] ?? null,
        ];
    }

    /**
     * Items with no screen yet.
     *
     * @return array<string, array{label: string, group: string|null, icon: string|null, sort: int, hidden: bool, phase: int|null, summary: string|null}>
     */
    public function planned(): array
    {
        return array_filter($this->items(), fn (array $item): bool => $item['phase'] !== null);
    }

    /**
     * @return array{groups?: array<string, array<string, mixed>>, items?: array<string, array<string, mixed>>}
     */
    public function overrides(): array
    {
        $overrides = $this->settings->get(self::SETTING, []);

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * @param  array{groups?: array<string, array<string, mixed>>, items?: array<string, array<string, mixed>>}  $overrides
     */
    public function saveOverrides(array $overrides): void
    {
        $this->settings->set(self::SETTING, $overrides);
    }
}

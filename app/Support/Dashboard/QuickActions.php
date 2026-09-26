<?php

namespace App\Support\Dashboard;

use Closure;

/**
 * The shortcuts on the dashboard. Each module adds its own from its service
 * provider — the dashboard does not know which modules exist — and each one
 * says who may see it, so nobody is offered a door they cannot open.
 */
class QuickActions
{
    /** @var array<string, array{label: string, description: string, icon: string, url: Closure(): string, visible: Closure(): bool, sort: int}> */
    protected array $actions = [];

    /**
     * @param  Closure(): string  $url
     * @param  Closure(): bool  $visible
     */
    public function add(string $key, string $label, string $description, string $icon, Closure $url, Closure $visible, int $sort = 0): void
    {
        $this->actions[$key] = compact('label', 'description', 'icon', 'url', 'visible', 'sort');
    }

    /**
     * The ones the signed-in user may use, in order, with their URLs.
     *
     * @return list<array{key: string, label: string, description: string, icon: string, url: string}>
     */
    public function visible(): array
    {
        return collect($this->actions)
            ->filter(fn (array $action): bool => (bool) ($action['visible'])())
            ->sortBy('sort')
            ->map(fn (array $action, string $key): array => [
                'key' => $key,
                'label' => $action['label'],
                'description' => $action['description'],
                'icon' => $action['icon'],
                'url' => ($action['url'])(),
            ])
            ->values()
            ->all();
    }
}

{{-- Quick actions: one tile per shortcut the user may use. --}}
<x-filament-widgets::widget>
    <style>
        .qa-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); }
        .qa-tile { display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.875rem 1rem; border-radius: 0.75rem; background: #fff; border: 1px solid var(--gray-200); text-decoration: none; color: inherit; transition: border-color 0.15s; }
        .qa-tile:hover, .qa-tile:focus-visible { border-color: var(--primary-500); outline: none; }
        .qa-icon { flex: none; width: 2.25rem; height: 2.25rem; padding: 0.5rem; border-radius: 0.5rem; color: var(--primary-600); background: color-mix(in srgb, var(--primary-500) 12%, transparent); }
        .qa-text { display: grid; gap: 0.125rem; min-width: 0; }
        .qa-text strong { font-size: 0.875rem; color: var(--gray-950); }
        .qa-text span { font-size: 0.75rem; line-height: 1.4; color: var(--gray-500); }
        :where(.dark) .qa-tile { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        :where(.dark) .qa-text strong { color: #fff; }
        :where(.dark) .qa-text span { color: var(--gray-400); }
        :where(.dark) .qa-icon { color: var(--primary-400); }
    </style>

    <nav class="qa-grid" aria-label="Quick actions">
        @foreach ($this->actions() as $action)
            <a class="qa-tile" href="{{ $action['url'] }}" wire:key="qa-{{ $action['key'] }}">
                <x-filament::icon :icon="$action['icon']" class="qa-icon" />
                <span class="qa-text">
                    <strong>{{ $action['label'] }}</strong>
                    <span>{{ $action['description'] }}</span>
                </span>
            </a>
        @endforeach
    </nav>
</x-filament-widgets::widget>

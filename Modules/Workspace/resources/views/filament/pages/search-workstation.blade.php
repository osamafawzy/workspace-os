{{--
    Search Workstation: type-ahead results, keyboard first.

    "/" puts the cursor in the box from anywhere on the page, the arrow keys
    move through the results and Enter opens the highlighted one's details.
    Scoped styles, because the panel's compiled CSS only carries the classes
    Filament itself uses.
--}}
<x-filament-panels::page>
    <style>
        .ws-search { display: grid; gap: 1rem; }
        .ws-box { position: relative; }
        .ws-box input { width: 100%; height: 3rem; padding: 0 1rem 0 2.75rem; font-size: 1rem; border-radius: 0.75rem; border: 1px solid var(--gray-300); background: #fff; color: var(--gray-950); outline: none; }
        .ws-box input:focus { border-color: var(--primary-500); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-500) 25%, transparent); }
        .ws-box__icon { position: absolute; left: 0.875rem; top: 50%; width: 1.25rem; height: 1.25rem; transform: translateY(-50%); color: var(--gray-400); pointer-events: none; }
        .ws-box__busy { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); font-size: 0.75rem; color: var(--gray-500); }
        .ws-hint { font-size: 0.8125rem; color: var(--gray-500); }
        .ws-hint kbd { padding: 0.05rem 0.35rem; border-radius: 0.25rem; border: 1px solid var(--gray-300); font-family: inherit; font-size: 0.75rem; }
        .ws-list { display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
        .ws-item { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem 1rem; padding: 0.875rem 1rem; border-radius: 0.75rem; background: #fff; border: 1px solid var(--gray-200); cursor: pointer; }
        .ws-item.is-active { border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); }
        .ws-item__main { display: grid; gap: 0.25rem; flex: 1 1 18rem; min-width: 0; }
        .ws-item__title { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .ws-item__name { font-size: 1rem; font-weight: 600; color: var(--gray-950); }
        .ws-item__meta { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.8125rem; color: var(--gray-600); }
        .ws-item__meta span { white-space: nowrap; }
        .ws-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .ws-item__matched { font-size: 0.75rem; color: var(--gray-500); }
        .ws-item__matched mark { padding: 0 0.25rem; border-radius: 0.25rem; background: color-mix(in srgb, var(--primary-500) 15%, transparent); color: inherit; }
        .ws-item__actions { display: flex; gap: 0.5rem; }
        .ws-empty { padding: 2rem 1rem; text-align: center; color: var(--gray-500); }
        .ws-fields { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.375rem; margin-top: 0.75rem; }

        :where(.dark) .ws-box input { background: var(--gray-900); border-color: rgb(255 255 255 / 0.15); color: #fff; }
        :where(.dark) .ws-item { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        :where(.dark) .ws-item.is-active { border-color: var(--primary-400); box-shadow: 0 0 0 1px var(--primary-400); }
        :where(.dark) .ws-item__name { color: #fff; }
        :where(.dark) .ws-item__meta { color: var(--gray-400); }
        :where(.dark) .ws-hint kbd { border-color: rgb(255 255 255 / 0.2); }
    </style>

    @php($results = $this->results)

    <div
        class="ws-search"
        x-data="{
            active: 0,
            items() { return [...this.$refs.list?.querySelectorAll('[data-result]') ?? []] },
            move(step) {
                const items = this.items()
                if (! items.length) return
                this.active = (this.active + step + items.length) % items.length
                items[this.active].scrollIntoView({ block: 'nearest' })
            },
            open() {
                const item = this.items()[this.active]
                if (item) $wire.mountAction('details', { workstation: Number(item.dataset.result) })
            },
        }"
        x-on:keydown.window="
            if ($event.key === '/' && ! $event.target.closest('input, textarea, select, [contenteditable], .fi-modal')) {
                $event.preventDefault()
                $refs.input.focus()
                $refs.input.select()
            }
        "
    >
        <div class="ws-box">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="ws-box__icon" />
            <input
                x-ref="input"
                type="search"
                autofocus
                autocomplete="off"
                spellcheck="false"
                maxlength="200"
                placeholder="Workstation ID, PC name, switch, port, rack, VLAN, IP or MAC"
                aria-label="Search workstations"
                wire:model.live.debounce.250ms="search"
                x-on:input="active = 0"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="open()"
            >
            <span class="ws-box__busy" wire:loading wire:target="search">Searching…</span>
        </div>

        <p class="ws-hint">
            <kbd>/</kbd> to search · <kbd>↑</kbd> <kbd>↓</kbd> to choose · <kbd>Enter</kbd> for details.
            Several words narrow it down: <span class="ws-mono">SW-11 Gi1/0/3</span>.
        </p>

        @if (trim($search) === '')
            <x-filament::section>
                <div class="ws-empty">
                    Start typing to find a workstation by anything written on it or on its cable.
                    <div class="ws-fields">
                        @foreach (['Workstation ID', 'Workstation Number', 'PC Name', 'Switch', 'Port', 'Port Split', 'Rack', 'VLAN', 'IP Address', 'MAC Address', 'Serial Numbers'] as $field)
                            <x-filament::badge color="gray">{{ $field }}</x-filament::badge>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @elseif ($results['rows'] === [])
            <x-filament::section>
                <p class="ws-empty">No workstation matches “{{ $search }}”.</p>
            </x-filament::section>
        @else
            <p class="ws-hint" aria-live="polite">
                @if ($results['total'] > count($results['rows']))
                    Showing the best {{ count($results['rows']) }} of {{ number_format($results['total']) }} matches. Add a word to narrow it down.
                @else
                    {{ trans_choice(':count match|:count matches', $results['total']) }}
                @endif
            </p>

            <ul class="ws-list" x-ref="list">
                @foreach ($results['rows'] as $index => $row)
                    <li
                        wire:key="result-{{ $row['id'] }}"
                        class="ws-item"
                        data-result="{{ $row['id'] }}"
                        x-bind:class="{ 'is-active': active === {{ $index }} }"
                        x-on:mouseenter="active = {{ $index }}"
                        x-on:click="if (! $event.target.closest('a, button')) open()"
                    >
                        <div class="ws-item__main">
                            <div class="ws-item__title">
                                <span class="ws-item__name">{{ $row['name'] }}</span>
                                <x-filament::badge :color="$row['status']->getColor()">{{ $row['status']->getLabel() }}</x-filament::badge>
                                @if ($row['floor'])
                                    <x-filament::badge color="gray">{{ $row['floor'] }}</x-filament::badge>
                                @endif
                                @unless ($row['placed'])
                                    <x-filament::badge color="warning">Not on the map</x-filament::badge>
                                @endunless
                            </div>

                            <div class="ws-item__meta">
                                @if ($row['computer']) <span>PC {{ $row['computer'] }}</span> @endif
                                @if ($row['port']) <span class="ws-mono">{{ $row['port'] }}</span> @endif
                                @if ($row['ip']) <span class="ws-mono">{{ $row['ip'] }}</span> @endif
                            </div>

                            @if ($row['matched'] !== [])
                                <div class="ws-item__matched">
                                    Matched
                                    @foreach ($row['matched'] as $match)
                                        <mark>{{ $match['label'] }}: {{ $match['value'] }}</mark>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="ws-item__actions">
                            {{ ($this->detailsAction)(['workstation' => $row['id']]) }}

                            @if ($row['locateUrl'])
                                <x-filament::button tag="a" :href="$row['locateUrl']" icon="heroicon-m-map-pin" size="sm">
                                    Locate
                                </x-filament::button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>

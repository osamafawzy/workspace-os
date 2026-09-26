{{--
    Scoped styles rather than utility classes: the panel ships Filament's own
    compiled CSS, which only contains the classes Filament itself uses.
--}}
<x-filament-panels::page>
    @php($entry = $this->entry())

    <style>
        .cs-soon { display: flex; flex-direction: column; align-items: center; gap: 1rem; padding: 2.5rem 1rem; text-align: center; }
        .cs-soon__icon { width: 3rem; height: 3rem; color: var(--gray-400); }
        .cs-soon__title { font-size: 1.25rem; font-weight: 600; color: var(--gray-950); }
        .cs-soon__text { max-width: 36rem; font-size: 0.875rem; line-height: 1.5; color: var(--gray-600); }
        :where(.dark) .cs-soon__title { color: #fff; }
        :where(.dark) .cs-soon__text { color: var(--gray-400); }
    </style>

    <x-filament::section>
        <div class="cs-soon">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="cs-soon__icon" />

            <x-filament::badge color="gray">Planned for phase {{ $entry['phase'] }}</x-filament::badge>

            <h2 class="cs-soon__title">{{ $entry['label'] }} is not built yet</h2>

            @if ($entry['summary'])
                <p class="cs-soon__text">{{ $entry['summary'] }}</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>

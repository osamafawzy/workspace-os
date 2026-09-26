{{--
    Scoped styles rather than utility classes: the panel ships Filament's own
    compiled CSS, which only contains the classes Filament itself uses.
--}}
<x-filament-panels::page>
    <style>
        .fm-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
        .fm-card { display: flex; flex-direction: column; gap: 0.875rem; padding: 1.125rem; border-radius: 0.75rem; background: #fff; border: 1px solid var(--gray-200); box-shadow: 0 1px 2px rgb(15 23 42 / 0.05); }
        .fm-card__head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; }
        .fm-card__name { font-size: 1rem; font-weight: 600; color: var(--gray-950); }
        .fm-card__level { font-size: 0.75rem; color: var(--gray-500); white-space: nowrap; }
        .fm-card__stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin: 0; }
        .fm-card__stats dt { font-size: 0.7rem; color: var(--gray-500); }
        .fm-card__stats dd { margin: 0; font-size: 1.125rem; font-weight: 600; color: var(--gray-950); font-variant-numeric: tabular-nums; }
        .fm-bar { height: 0.375rem; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
        .fm-bar__fill { height: 100%; background: var(--primary-500); }
        .fm-card__actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: auto; }
        .fm-empty { padding: 2.5rem 1rem; text-align: center; color: var(--gray-500); }

        :where(.dark) .fm-card { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        :where(.dark) .fm-card__name,
        :where(.dark) .fm-card__stats dd { color: #fff; }
        :where(.dark) .fm-card__level,
        :where(.dark) .fm-card__stats dt { color: var(--gray-400); }
        :where(.dark) .fm-bar { background: rgb(255 255 255 / 0.1); }
    </style>

    @php($floors = $this->floors())

    @if ($floors->isEmpty())
        <x-filament::section>
            <p class="fm-empty">No floors yet. Add one from Floor Setup to start mapping it.</p>
        </x-filament::section>
    @else
        <div class="fm-grid">
            @foreach ($floors as $floor)
                @php($share = $floor->workstations_count ? round($floor->placed_count / $floor->workstations_count * 100) : 0)

                <article class="fm-card">
                    <div class="fm-card__head">
                        <h3 class="fm-card__name">{{ $floor->name }}</h3>
                        <span class="fm-card__level">Level {{ $floor->level }}</span>
                    </div>

                    <dl class="fm-card__stats">
                        <div><dt>Workstations</dt><dd>{{ $floor->workstations_count }}</dd></div>
                        <div><dt>On the map</dt><dd>{{ $floor->placed_count }}</dd></div>
                        <div><dt>Drawing</dt><dd>{{ $floor->hasPlan() ? 'Yes' : 'No' }}</dd></div>
                    </dl>

                    <div class="fm-bar" role="img" aria-label="{{ $share }}% of workstations placed on the map">
                        <div class="fm-bar__fill" style="width: {{ $share }}%"></div>
                    </div>

                    <div class="fm-card__actions">
                        <x-filament::button tag="a" :href="$this->planUrl($floor)" icon="heroicon-m-map" size="sm">
                            Open map
                        </x-filament::button>

                        <x-filament::button tag="a" :href="route('building.floor', $floor)" target="_blank" color="gray" icon="heroicon-m-cube" size="sm">
                            3D view
                        </x-filament::button>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>

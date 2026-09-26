{{--
    One desk's record, read-only, for the details slide-over.

    Everything here is text people typed, and Blade escapes it. Scoped styles,
    because the panel's compiled CSS only carries the classes Filament uses.
--}}
@php
    /** @var \Modules\Workspace\Models\Workstation $desk */
    $status = $desk->status;
@endphp

<style>
    .wd { display: grid; gap: 1.25rem; font-size: 0.875rem; }
    .wd-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
    .wd-muted { color: var(--gray-500); }
    .wd-group h3 { margin: 0 0 0.5rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); }
    .wd-rows { display: grid; grid-template-columns: minmax(8rem, auto) 1fr; gap: 0.375rem 1rem; margin: 0; }
    .wd-rows dt { color: var(--gray-500); }
    .wd-rows dd { margin: 0; color: var(--gray-950); word-break: break-word; }
    .wd-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.8125rem; }
    .wd-history { display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
    .wd-history li { display: flex; justify-content: space-between; gap: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gray-100); }
    .wd-history b { font-weight: 500; color: var(--gray-950); }
    :where(.dark) .wd-rows dd,
    :where(.dark) .wd-history b { color: #fff; }
    :where(.dark) .wd-muted,
    :where(.dark) .wd-rows dt,
    :where(.dark) .wd-group h3 { color: var(--gray-400); }
    :where(.dark) .wd-history li { border-color: rgb(255 255 255 / 0.08); }
</style>

<div class="wd">
    <div class="wd-head">
        <x-filament::badge :color="$status->getColor()" :icon="$status->getIcon()">{{ $status->getLabel() }}</x-filament::badge>

        @if ($desk->mapObject)
            <x-filament::badge color="gray" icon="heroicon-m-map-pin">On the floor map</x-filament::badge>
        @else
            <x-filament::badge color="warning">Not on the floor map yet</x-filament::badge>
        @endif
    </div>

    @forelse ($desk->detailGroups() as $group)
        <section class="wd-group">
            <h3>{{ $group['title'] }}</h3>
            <dl class="wd-rows">
                @foreach ($group['rows'] as $row)
                    <dt>{{ $row['label'] }}</dt>
                    <dd @class(['wd-mono' => $row['mono'] && $row['value'] !== null, 'wd-muted' => $row['value'] === null])>{{ $row['value'] ?? '—' }}</dd>
                @endforeach
            </dl>
        </section>
    @empty
        <p class="wd-muted">Nothing has been recorded about this desk yet.</p>
    @endforelse

    @if ($history !== null)
        <section class="wd-group">
            <h3>Recent history</h3>

            @if ($history->isEmpty())
                <p class="wd-muted">Nothing recorded.</p>
            @else
                <ul class="wd-history">
                    @foreach ($history as $entry)
                        <li>
                            <span><b>{{ ucfirst($entry->action) }}</b> <span class="wd-muted">by {{ $entry->user_name ?? 'System' }}</span></span>
                            <time class="wd-muted" datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->format('Y-m-d H:i:s') }}">{{ $entry->created_at->diffForHumans() }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>

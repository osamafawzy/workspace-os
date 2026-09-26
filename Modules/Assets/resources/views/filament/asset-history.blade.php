{{--
    An asset's history, newest first: what happened, who did it, and each
    field as it was and as it became. Used as an infolist entry ($getState)
    and as modal content ($entries).
--}}
@php
    $entries = collect(isset($getState) ? $getState() : $entries);
    $labels = \Modules\Assets\Models\Asset::TRACKED;
@endphp

<style>
    .ah-list { display: grid; gap: 0.75rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem; }
    .ah-item { padding-bottom: 0.75rem; border-bottom: 1px solid var(--gray-100); }
    .ah-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.25rem 1rem; }
    .ah-event { font-weight: 600; color: var(--gray-950); }
    .ah-reason { padding: 0.05rem 0.4rem; border-radius: 0.25rem; background: color-mix(in srgb, var(--primary-500) 14%, transparent); color: var(--primary-700); font-size: 0.75rem; }
    :where(.dark) .ah-reason { color: var(--primary-300); }
    .ah-muted { color: var(--gray-500); }
    .ah-changes { display: grid; grid-template-columns: minmax(7rem, auto) 1fr; gap: 0.2rem 0.75rem; margin: 0.4rem 0 0; font-size: 0.8125rem; }
    .ah-changes dt { color: var(--gray-500); }
    .ah-changes dd { margin: 0; color: var(--gray-700); word-break: break-word; }
    .ah-from { color: var(--gray-500); text-decoration: line-through; }
    :where(.dark) .ah-event { color: #fff; }
    :where(.dark) .ah-changes dd { color: var(--gray-300); }
    :where(.dark) .ah-muted, :where(.dark) .ah-changes dt, :where(.dark) .ah-from { color: var(--gray-400); }
    :where(.dark) .ah-item { border-color: rgb(255 255 255 / 0.08); }
</style>

@if ($entries->isEmpty())
    <p class="ah-muted">Nothing recorded yet.</p>
@else
    <ul class="ah-list">
        @foreach ($entries as $entry)
            <li class="ah-item">
                <div class="ah-head">
                    <span>
                        <span class="ah-event">{{ ucfirst($entry->event) }}</span>
                        @if ($entry->reason)
                            <span class="ah-reason">{{ $entry->reason }}</span>
                        @endif
                        <span class="ah-muted">by {{ $entry->user_name ?? 'System' }}</span>
                    </span>
                    <time class="ah-muted" datetime="{{ $entry->created_at?->toIso8601String() }}" title="{{ $entry->created_at?->format('Y-m-d H:i:s') }}">{{ $entry->created_at?->format('Y-m-d H:i') }}</time>
                </div>

                @if ($entry->changes)
                    <dl class="ah-changes">
                        @foreach ($entry->changes as $field => $change)
                            <dt>{{ $labels[$field] ?? str_replace('_', ' ', $field) }}</dt>
                            <dd>
                                @if ($entry->event !== 'registered')
                                    <span class="ah-from">{{ $change['from'] ?? '—' }}</span> →
                                @endif
                                {{ $change['to'] ?? '—' }}
                            </dd>
                        @endforeach
                    </dl>
                @endif
            </li>
        @endforeach
    </ul>
@endif

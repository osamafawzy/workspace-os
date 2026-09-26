{{--
    An employee's recent audit entries. Personal values were redacted when the
    entries were written, so nothing here needs hiding again.
--}}
@php($entries = collect($getState()))

<style>
    .eh-list { display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem; }
    .eh-list li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.25rem 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gray-100); }
    .eh-list b { font-weight: 500; color: var(--gray-950); }
    .eh-muted { color: var(--gray-500); }
    :where(.dark) .eh-list b { color: #fff; }
    :where(.dark) .eh-muted { color: var(--gray-400); }
    :where(.dark) .eh-list li { border-color: rgb(255 255 255 / 0.08); }
</style>

@if ($entries->isEmpty())
    <p class="eh-muted">Nothing recorded yet.</p>
@else
    <ul class="eh-list">
        @foreach ($entries as $entry)
            <li>
                <span>
                    <b>{{ ucfirst($entry->action) }}</b>
                    @if (str_contains((string) $entry->record_label, 'emergency contact'))
                        <span class="eh-muted">{{ \Illuminate\Support\Str::after($entry->record_label, '· ') }}</span>
                    @endif
                    <span class="eh-muted">by {{ $entry->user_name ?? 'System' }}</span>
                    @if ($entry->new_values)
                        <span class="eh-muted">— {{ collect(array_keys($entry->new_values))->map(fn ($field) => str_replace('_', ' ', $field))->take(6)->implode(', ') }}</span>
                    @endif
                </span>
                <time class="eh-muted" datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->format('Y-m-d H:i:s') }}">{{ $entry->created_at->diffForHumans() }}</time>
            </li>
        @endforeach
    </ul>
@endif

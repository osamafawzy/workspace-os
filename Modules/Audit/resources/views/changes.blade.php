{{--
    One audit entry, field by field: what it was, what it became.

    Values are escaped like everything else — they are text people typed.
    Scoped styles, because the panel's compiled CSS only carries the utility
    classes Filament itself uses.
--}}
@php
    $old = $log->old_values ?? [];
    $new = $log->new_values ?? [];
    $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));

    $show = fn (mixed $value): string => match (true) {
        $value === null => '—',
        is_bool($value) => $value ? 'Yes' : 'No',
        is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        default => (string) $value,
    };
@endphp

<style>
    .al-entry { display: grid; gap: 1rem; font-size: 0.875rem; }
    .al-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 0.5rem 1.5rem; margin: 0; }
    .al-meta dt { font-size: 0.75rem; color: var(--gray-500); }
    .al-meta dd { margin: 0; font-weight: 500; color: var(--gray-950); }
    .al-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .al-scroll { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: 0.5rem; }
    .al-table { width: 100%; border-collapse: collapse; text-align: left; }
    .al-table th { padding: 0.5rem 0.75rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gray-500); background: var(--gray-50); }
    .al-table td { padding: 0.5rem 0.75rem; vertical-align: top; border-top: 1px solid var(--gray-200); }
    .al-field { font-weight: 500; color: var(--gray-950); white-space: nowrap; }
    .al-value { font-size: 0.75rem; white-space: pre-wrap; word-break: break-all; }
    .al-before { color: var(--gray-500); }
    .al-after { color: var(--gray-950); }
    .al-empty { color: var(--gray-500); }

    :where(.dark) .al-meta dd,
    :where(.dark) .al-field,
    :where(.dark) .al-after { color: #fff; }
    :where(.dark) .al-meta dt,
    :where(.dark) .al-before,
    :where(.dark) .al-empty,
    :where(.dark) .al-table th { color: var(--gray-400); }
    :where(.dark) .al-scroll,
    :where(.dark) .al-table td { border-color: rgb(255 255 255 / 0.1); }
    :where(.dark) .al-table th { background: rgb(255 255 255 / 0.05); }
</style>

<div class="al-entry">
    <dl class="al-meta">
        <div><dt>When</dt><dd>{{ $log->created_at->format('Y-m-d H:i:s') }}</dd></div>
        <div><dt>User</dt><dd>{{ $log->user_name ?? 'System' }}</dd></div>
        <div><dt>IP</dt><dd class="al-mono">{{ $log->ip_address ?? '—' }}</dd></div>
        <div><dt>Host</dt><dd class="al-mono">{{ $log->hostname ?? '—' }}</dd></div>
    </dl>

    @if ($fields === [])
        <p class="al-empty">No field values were recorded for this entry.</p>
    @else
        <div class="al-scroll">
            <table class="al-table">
                <thead>
                    <tr><th>Field</th><th>Before</th><th>After</th></tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field)
                        <tr>
                            <td class="al-field">{{ str_replace('_', ' ', $field) }}</td>
                            <td class="al-value al-mono al-before">{{ array_key_exists($field, $old) ? $show($old[$field]) : '—' }}</td>
                            <td class="al-value al-mono al-after">{{ array_key_exists($field, $new) ? $show($new[$field]) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

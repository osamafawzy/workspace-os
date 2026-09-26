{{--
    Scoped styles rather than utility classes: the panel ships Filament's own
    compiled CSS, which only contains the classes Filament itself uses.
--}}
<x-filament-panels::page>
    <style>
        .imp-stats { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); }
        .imp-stat { padding: 0.875rem 1rem; border-radius: 0.75rem; background: #fff; border: 1px solid var(--gray-200); }
        .imp-stat dt { font-size: 0.75rem; color: var(--gray-500); }
        .imp-stat dd { margin: 0.125rem 0 0; font-size: 1.5rem; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--gray-950); }
        .imp-stat--good dd { color: var(--success-600); }
        .imp-stat--info dd { color: var(--info-600); }
        .imp-stat--warn dd { color: var(--warning-600); }
        .imp-stat--bad dd { color: var(--danger-600); }
        .imp-file { font-size: 0.875rem; color: var(--gray-600); }
        .imp-file strong { color: var(--gray-950); }
        .imp-note { margin-top: 0.5rem; font-size: 0.8125rem; color: var(--gray-500); }

        :where(.dark) .imp-stat { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        :where(.dark) .imp-stat dd,
        :where(.dark) .imp-file strong { color: #fff; }
        :where(.dark) .imp-stat--good dd { color: var(--success-400); }
        :where(.dark) .imp-stat--info dd { color: var(--info-400); }
        :where(.dark) .imp-stat--warn dd { color: var(--warning-400); }
        :where(.dark) .imp-stat--bad dd { color: var(--danger-400); }
        :where(.dark) .imp-file,
        :where(.dark) .imp-stat dt { color: var(--gray-400); }
    </style>

    @php($batch = $this->batchRecord())

    @if (! $batch)
        <form wire:submit="checkFile">
            {{ $this->form }}

            <div style="margin-top: 1rem;">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="checkFile,data.file">
                    <span wire:loading.remove wire:target="checkFile">Check file</span>
                    <span wire:loading wire:target="checkFile">Checking…</span>
                </x-filament::button>
            </div>
        </form>
    @else
        @php($summary = $batch->summary ?? [])
        @php($result = $summary['result'] ?? null)

        <x-filament::section>
            <p class="imp-file">
                <strong>{{ $batch->file_name }}</strong>
                &middot; {{ number_format($summary['total'] ?? 0) }} row(s)
                &middot;
                @if ($result)
                    imported {{ $batch->imported_at?->diffForHumans() }}
                @elseif ($batch->status === \App\Models\ImportBatch::CANCELLED)
                    cancelled
                @else
                    checked, not imported yet
                @endif
            </p>

            <dl class="imp-stats" style="margin-top: 1rem;">
                @if ($result)
                    <div class="imp-stat"><dt>Total</dt><dd>{{ number_format($summary['total'] ?? 0) }}</dd></div>
                    <div class="imp-stat imp-stat--good"><dt>Successful</dt><dd>{{ number_format(($result['imported'] ?? 0) + ($result['updated'] ?? 0)) }}</dd></div>
                    <div class="imp-stat imp-stat--warn"><dt>Duplicates</dt><dd>{{ number_format($result['duplicates'] ?? 0) }}</dd></div>
                    <div class="imp-stat imp-stat--bad"><dt>Failed</dt><dd>{{ number_format(($result['failed'] ?? 0) + ($result['invalid'] ?? 0)) }}</dd></div>
                @else
                    <div class="imp-stat imp-stat--good"><dt>New</dt><dd>{{ number_format($summary['new'] ?? 0) }}</dd></div>
                    <div class="imp-stat imp-stat--info"><dt>Will update</dt><dd>{{ number_format($summary['update'] ?? 0) }}</dd></div>
                    <div class="imp-stat"><dt>Already exist</dt><dd>{{ number_format($summary['unchanged'] ?? 0) }}</dd></div>
                    <div class="imp-stat imp-stat--warn"><dt>Duplicates</dt><dd>{{ number_format($summary['duplicate'] ?? 0) }}</dd></div>
                    <div class="imp-stat imp-stat--bad"><dt>Invalid</dt><dd>{{ number_format($summary['invalid'] ?? 0) }}</dd></div>
                @endif
            </dl>

            @if ($result)
                <p class="imp-note">
                    {{ number_format($result['imported'] ?? 0) }} created, {{ number_format($result['updated'] ?? 0) }} updated.
                    Duplicates are rows already in the application or repeated in the file; failed rows were invalid or could not be saved.
                    @if (($result['duplicates'] ?? 0) + ($result['failed'] ?? 0) + ($result['invalid'] ?? 0) > 0)
                        The error report lists every one with why.
                    @endif
                </p>
            @endif

            @if (! empty($summary['ignored_headings']))
                <p class="imp-note">
                    Columns not recognised, and ignored: {{ implode(', ', $summary['ignored_headings']) }}.
                </p>
            @endif
        </x-filament::section>

        {{ $this->table }}
    @endif
</x-filament-panels::page>

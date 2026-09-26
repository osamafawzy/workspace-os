{{-- All Reports: one card per report, under its heading. --}}
<x-filament-panels::page>
    <style>
        .rp-group { display: grid; gap: 0.75rem; }
        .rp-group h2 { margin: 0; font-size: 0.8125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); }
        .rp-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
        .rp-card { display: grid; gap: 0.375rem; padding: 1rem 1.125rem; border-radius: 0.75rem; background: #fff; border: 1px solid var(--gray-200); text-decoration: none; color: inherit; }
        .rp-card:hover { border-color: var(--primary-500); }
        .rp-card strong { font-size: 0.9375rem; color: var(--gray-950); }
        .rp-card span { font-size: 0.8125rem; line-height: 1.45; color: var(--gray-600); }
        .rp-empty { color: var(--gray-500); }
        :where(.dark) .rp-card { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        :where(.dark) .rp-card strong { color: #fff; }
        :where(.dark) .rp-card span { color: var(--gray-400); }
    </style>

    @forelse ($this->groups() as $group => $reports)
        <section class="rp-group">
            <h2>{{ $group }}</h2>
            <div class="rp-grid">
                @foreach ($reports as $report)
                    <a class="rp-card" href="{{ $this->reportUrl($report) }}">
                        <strong>{{ $report::label() }}</strong>
                        <span>{{ $report::description() }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <x-filament::section>
            <p class="rp-empty">There are no reports you are allowed to open.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>

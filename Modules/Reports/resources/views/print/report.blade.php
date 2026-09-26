{{--
    A report as paper. Columns and values are the report's own, read the same
    way as on screen and in the export.
--}}
@php
    $report = $this->report();
    $columns = $this->printColumns();
    $rows = $this->rows();
    $total = $this->total();
    $filters = $this->activeFilterSummary();
    $logo = $this->logoUrl();
    $landscape = $report->landscape();
@endphp

<div>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111827; font: 9pt/1.35 "Segoe UI", Tahoma, Arial, "Noto Sans Arabic", sans-serif; }
        .pr-toolbar { position: sticky; top: 0; z-index: 1; display: flex; gap: 0.5rem; justify-content: center; padding: 0.75rem; background: #111827; }
        .pr-toolbar a, .pr-toolbar button { padding: 0.5rem 1rem; border-radius: 0.375rem; border: 0; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .pr-toolbar button { background: #2563eb; color: #fff; }
        .pr-toolbar a { background: #374151; color: #fff; }
        .pr-sheet { width: {{ $landscape ? '297mm' : '210mm' }}; margin: 1rem auto; padding: 10mm; background: #fff; box-shadow: 0 2px 12px rgb(0 0 0 / 0.15); }
        .pr-head { display: flex; justify-content: space-between; gap: 1rem; padding-bottom: 3mm; margin-bottom: 3mm; border-bottom: 2px solid #111827; }
        .pr-head img { max-height: 11mm; max-width: 45mm; }
        .pr-head h1 { margin: 0; font-size: 13pt; }
        .pr-meta { font-size: 8pt; color: #4b5563; }
        .pr-note { margin: 0 0 3mm; font-size: 8.5pt; color: #b45309; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 1mm 1.5mm; text-align: left; vertical-align: top; word-break: break-word; }
        th { background: #f3f4f6; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.03em; }
        thead { display: table-header-group; }
        .pr-totals td { background: #f3f4f6; font-weight: 700; }
        td.mono { font-family: Consolas, "Courier New", monospace; }
        .pr-foot { margin-top: 3mm; display: flex; justify-content: space-between; font-size: 7.5pt; color: #6b7280; }
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 8mm; }
        @media print {
            body { background: #fff; }
            .pr-toolbar { display: none; }
            .pr-sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
            tr { page-break-inside: avoid; }
        }
    </style>

    <nav class="pr-toolbar">
        <button type="button" onclick="window.print()">Print or save as PDF</button>
        <a href="{{ $this->backUrl() }}">Back to the report</a>
    </nav>

    <article class="pr-sheet">
        <header class="pr-head">
            <div>
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $this->companyName() }}">
                @endif
                <h1>{{ $report::label() }}</h1>
                <div class="pr-meta">{{ $this->companyName() }}</div>
            </div>
            <div class="pr-meta" style="text-align: right">
                <div>{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('row', $total) }}</div>
                @if (filled($this->tableSearch))
                    <div>Search: “{{ $this->tableSearch }}”</div>
                @endif
                @if ($filters !== '')
                    <div>{{ $filters }}</div>
                @endif
                <div>Printed {{ now()->format('Y-m-d H:i') }} by {{ auth()->user()?->name }}</div>
            </div>
        </header>

        @if ($rows->count() > \Modules\Reports\Filament\Admin\Pages\PrintReport::MAX_ROWS)
            <p class="pr-note">Only the first {{ number_format(\Modules\Reports\Filament\Admin\Pages\PrintReport::MAX_ROWS) }} rows are printed. Narrow the filters, or export the whole report to Excel.</p>
        @endif

        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows->take(\Modules\Reports\Filament\Admin\Pages\PrintReport::MAX_ROWS) as $record)
                    <tr>
                        @foreach ($columns as $column)
                            <td @class(['mono' => $column->isMono()]) dir="auto">{{ $column->value($record) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}">Nothing matches.</td></tr>
                @endforelse
            </tbody>

            @if ($rows->isNotEmpty() && collect($columns)->contains(fn ($column) => $column->isTotalled()))
                <tfoot>
                    <tr class="pr-totals">
                        @foreach ($columns as $index => $column)
                            <td class="{{ $column->isMono() ? 'mono' : '' }}">
                                @if ($column->isTotalled())
                                    {{ number_format($rows->sum(fn ($record) => (int) $column->value($record))) }}
                                @elseif ($index === 0)
                                    Total
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>

        <footer class="pr-foot">
            <span>{{ $report::label() }}</span>
            <span>{{ $this->companyName() }}</span>
        </footer>
    </article>
</div>

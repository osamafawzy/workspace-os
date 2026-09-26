{{--
    The New Data report: a quick print of a draft, or the record of a release.
    Landscape A4, because the rows are wide.
--}}
@php
    $batch = $this->releaseBatch;
    $items = $this->items();
    $names = $this->employeeNames();
    $draft = $batch->isDraft();
    $logo = $this->logoUrl();
@endphp

<div>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111827; font: 9.5pt/1.35 "Segoe UI", Tahoma, Arial, "Noto Sans Arabic", sans-serif; }
        .rb-toolbar { position: sticky; top: 0; z-index: 1; display: flex; gap: 0.5rem; justify-content: center; padding: 0.75rem; background: #111827; }
        .rb-toolbar a, .rb-toolbar button { padding: 0.5rem 1rem; border-radius: 0.375rem; border: 0; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .rb-toolbar button { background: #2563eb; color: #fff; }
        .rb-toolbar a { background: #374151; color: #fff; }
        .rb-sheet { width: 297mm; min-height: 210mm; margin: 1rem auto; padding: 10mm 12mm; background: #fff; box-shadow: 0 2px 12px rgb(0 0 0 / 0.15); position: relative; }
        .rb-head { display: flex; justify-content: space-between; gap: 1rem; padding-bottom: 3mm; border-bottom: 2px solid #111827; }
        .rb-head img { max-height: 12mm; max-width: 50mm; }
        .rb-head h1 { margin: 0; font-size: 14pt; }
        .rb-number { font-family: Consolas, "Courier New", monospace; font-weight: 700; font-size: 11pt; }
        .rb-draft { position: absolute; top: 38%; left: 0; right: 0; text-align: center; font-size: 54pt; font-weight: 800; color: rgb(220 38 38 / 0.08); transform: rotate(-18deg); pointer-events: none; }
        .rb-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1mm 5mm; margin: 3mm 0; }
        .rb-meta div { border-bottom: 1px solid #d1d5db; padding-bottom: 0.5mm; }
        .rb-meta span { display: block; font-size: 7.5pt; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 1.2mm 1.5mm; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.03em; }
        td.mono { font-family: Consolas, "Courier New", monospace; }
        .rb-problem { color: #b91c1c; }
        .rb-signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8mm; margin-top: 8mm; }
        .rb-sign { border-top: 1px solid #111827; padding-top: 1mm; font-size: 8.5pt; }
        .rb-foot { margin-top: 5mm; display: flex; justify-content: space-between; font-size: 7.5pt; color: #6b7280; }
        @page { size: A4 landscape; margin: 0; }
        @media print {
            body { background: #fff; }
            .rb-toolbar { display: none; }
            .rb-sheet { margin: 0; box-shadow: none; }
            tr { page-break-inside: avoid; }
        }
    </style>

    <nav class="rb-toolbar">
        <button type="button" onclick="window.print()">Print or save as PDF</button>
        <a href="{{ $this->backUrl() }}">Back to the batch</a>
    </nav>

    <article class="rb-sheet">
        @if ($draft)
            <div class="rb-draft" aria-hidden="true">NOT RELEASED</div>
        @endif

        <header class="rb-head">
            <div>
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $this->companyName() }}">
                @endif
                <div><strong>{{ $this->companyName() }}</strong></div>
            </div>
            <div style="text-align: right">
                <h1>{{ $draft ? 'New Data — Quick Print' : 'New Data Report' }}</h1>
                <div class="rb-number">{{ $batch->number }}</div>
                <div>{{ $draft ? 'Draft, printed '.now()->format('Y-m-d H:i') : 'Released '.$batch->released_at?->format('Y-m-d H:i').' by '.$batch->released_by_name }}</div>
            </div>
        </header>

        <div class="rb-meta">
            <div><span>Type</span>{{ $batch->assetType?->name }}</div>
            <div><span>Model</span>{{ $batch->assetModel?->fullName() ?? '—' }}</div>
            <div><span>Site / location</span>{{ collect([$batch->site?->name, $batch->location?->name])->filter()->implode(' · ') ?: '—' }}</div>
            <div><span>Account</span>{{ $batch->account?->name ?? '—' }}</div>
            <div><span>Purchased</span>{{ $batch->purchase_date?->format('Y-m-d') ?? '—' }}</div>
            <div><span>Warranty until</span>{{ $batch->warranty_expires_at?->format('Y-m-d') ?? '—' }}</div>
            <div><span>Staged by</span>{{ $batch->created_by_name ?? '—' }}</div>
            <div><span>Rows</span>{{ $items->count() }}, {{ $items->whereNotNull('employee_oid')->count() }} to employees</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 7mm">#</th>
                    <th>Serial number</th>
                    <th>Asset tag</th>
                    <th>Computer name</th>
                    <th>OID</th>
                    <th>Employee</th>
                    <th>Condition</th>
                    <th>{{ $draft ? 'Checks' : 'Handover form' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono">{{ $item->serial_number }}</td>
                        <td class="mono">{{ $item->asset_tag }}</td>
                        <td>{{ $item->computer_name }}</td>
                        <td>{{ $item->employee_oid ?? 'Stock' }}</td>
                        <td dir="auto">{{ $item->employee?->name ?? ($item->employee_oid ? ($names[mb_strtolower($item->employee_oid)] ?? '—') : '') }}</td>
                        <td>{{ $item->condition->getLabel() }}</td>
                        @if ($draft)
                            <td class="{{ $item->hasErrors() ? 'rb-problem' : '' }}">
                                {{ $item->findings === null ? 'Not checked' : (collect($item->allFindings())->where('level', 'error')->pluck('text')->implode(' ') ?: 'OK') }}
                            </td>
                        @else
                            <td class="mono">{{ $item->handoverForm?->number ?? 'Stock' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (filled($batch->notes))
            <p><b>Notes:</b> {{ $batch->notes }}</p>
        @endif

        <section class="rb-signatures">
            <div class="rb-sign">Prepared by<br><br>Signature / date</div>
            <div class="rb-sign">Checked by<br><br>Signature / date</div>
            <div class="rb-sign">Approved by<br><br>Signature / date</div>
        </section>

        <footer class="rb-foot">
            <span>{{ $batch->number }}</span>
            <span>{{ $draft ? 'Quick print — nothing released, nothing archived' : ($batch->print_count > 1 ? 'Copy '.$batch->print_count : 'Original') }}</span>
        </footer>
    </article>
</div>

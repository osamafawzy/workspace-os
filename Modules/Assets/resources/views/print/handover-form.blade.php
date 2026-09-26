{{--
    The handover form / return receipt, sized for A4.

    Everything printed comes from the form's frozen snapshot, and is escaped:
    it is text people typed. `dir="auto"` on names lets an Arabic name run
    right to left inside an English form.
--}}
@php
    $form = $this->handoverForm;
    $s = $form->snapshot;
    $employee = $s['employee'] ?? [];
    $assets = $s['assets'] ?? [];
    $isReturn = $form->kind === \Modules\Assets\Models\HandoverForm::RETURN;
    $logo = $this->logoUrl();
    $contacts = $this->showsContacts() ? collect($s['contacts'] ?? [])->keyBy('slot') : null;
    $date = fn (?string $iso, string $format = 'Y-m-d H:i') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($format) : '';
@endphp

<div class="hf-page">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111827; font: 10.5pt/1.4 "Segoe UI", Tahoma, Arial, "Noto Sans Arabic", sans-serif; }
        .hf-toolbar { position: sticky; top: 0; z-index: 1; display: flex; gap: 0.5rem; justify-content: center; padding: 0.75rem; background: #111827; }
        .hf-toolbar a, .hf-toolbar button { padding: 0.5rem 1rem; border-radius: 0.375rem; border: 0; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .hf-toolbar button { background: #2563eb; color: #fff; }
        .hf-toolbar a { background: #374151; color: #fff; }
        .hf-sheet { width: 210mm; min-height: 297mm; margin: 1rem auto; padding: 14mm 14mm 12mm; background: #fff; box-shadow: 0 2px 12px rgb(0 0 0 / 0.15); position: relative; }
        .hf-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 4mm; border-bottom: 2px solid #111827; }
        .hf-brand img { max-height: 16mm; max-width: 60mm; }
        .hf-brand strong { display: block; font-size: 14pt; }
        .hf-title { text-align: right; }
        .hf-title h1 { margin: 0; font-size: 15pt; letter-spacing: 0.02em; }
        .hf-title div { font-size: 9.5pt; color: #374151; }
        .hf-number { font-family: Consolas, "Courier New", monospace; font-size: 11pt; font-weight: 700; }
        .hf-copy { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 60pt; font-weight: 800; color: rgb(17 24 39 / 0.06); transform: rotate(-24deg); pointer-events: none; }
        h2 { margin: 5mm 0 2mm; font-size: 10pt; text-transform: uppercase; letter-spacing: 0.06em; color: #374151; }
        .hf-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5mm 5mm; }
        .hf-grid div { border-bottom: 1px solid #d1d5db; padding-bottom: 1mm; min-height: 9mm; }
        .hf-grid span { display: block; font-size: 8pt; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 1.5mm 2mm; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.04em; }
        td.mono { font-family: Consolas, "Courier New", monospace; }
        .hf-blank td { height: 9mm; }
        .hf-statement { margin: 5mm 0 0; padding: 3mm; border: 1px solid #d1d5db; background: #f9fafb; font-size: 9.5pt; }
        .hf-notes { margin: 3mm 0 0; font-size: 9.5pt; }
        .hf-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 10mm; }
        .hf-sign { border-top: 1px solid #111827; padding-top: 1.5mm; font-size: 9pt; }
        .hf-sign b { display: block; font-size: 10pt; }
        .hf-sign .line { margin-top: 9mm; border-top: 1px dotted #6b7280; padding-top: 1mm; color: #6b7280; }
        .hf-foot { margin-top: 8mm; display: flex; justify-content: space-between; font-size: 8pt; color: #6b7280; }
        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; }
            .hf-toolbar { display: none; }
            .hf-sheet { margin: 0; box-shadow: none; }
            tr, .hf-signatures { page-break-inside: avoid; }
        }
    </style>

    <nav class="hf-toolbar">
        <button type="button" onclick="window.print()">Print or save as PDF</button>
        <a href="{{ $this->backUrl() }}">Back to the forms</a>
    </nav>

    <article class="hf-sheet">
        @if ($this->printNumber > 1)
            <div class="hf-copy" aria-hidden="true">COPY</div>
        @endif

        <header class="hf-head">
            <div class="hf-brand">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $s['company']['name'] ?? '' }}">
                @endif
                <strong>{{ $s['company']['name'] ?? '' }}</strong>
            </div>
            <div class="hf-title">
                <h1>{{ $form->title() }}</h1>
                <div class="hf-number">{{ $form->number }}</div>
                <div>{{ $isReturn ? 'Returned '.$date($s['returned_at'] ?? null) : 'Issued '.$date($s['issued_at'] ?? null) }}</div>
            </div>
        </header>

        <h2>Employee</h2>
        <div class="hf-grid">
            <div><span>Name</span><b dir="auto">{{ $employee['name'] ?? '' }}</b></div>
            <div><span>OID</span>{{ $employee['oid'] ?? '' }}</div>
            <div><span>Employee number</span>{{ $employee['employee_number'] ?? '' }}</div>
            <div><span>Job title</span>{{ $employee['job_title'] ?? '' }}</div>
            <div><span>Department</span>{{ $employee['department'] ?? '' }}</div>
            <div><span>Account</span>{{ $employee['account'] ?? '' }}</div>
            <div><span>Site / location</span>{{ collect([$employee['site'] ?? null, $employee['location'] ?? null])->filter()->implode(' · ') }}</div>
            <div><span>Mobile</span>{{ $employee['mobile'] ?? '' }}</div>
            <div><span>Email</span>{{ $employee['email'] ?? '' }}</div>
        </div>

        @unless ($isReturn)
            <h2>Emergency contacts</h2>
            <table>
                <thead><tr><th style="width: 8mm">#</th><th>Name</th><th>Relationship</th><th>Phone</th></tr></thead>
                <tbody class="{{ $contacts ? '' : 'hf-blank' }}">
                    @foreach ([1, 2] as $slot)
                        @php($contact = $contacts?->get($slot))
                        <tr>
                            <td>{{ $slot }}</td>
                            <td dir="auto">{{ $contact['name'] ?? '' }}</td>
                            <td>{{ $contact['relationship'] ?? '' }}</td>
                            <td>{{ $contact['phone'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endunless

        <h2>{{ $isReturn ? 'Assets returned' : 'Assets handed over' }}</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 8mm">#</th>
                    <th>Type</th>
                    <th>Make / model</th>
                    <th>Serial number</th>
                    <th>Asset tag</th>
                    <th>Computer name</th>
                    <th>{{ $isReturn ? 'Condition in' : 'Condition' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($assets as $index => $asset)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $asset['type'] ?? '' }}</td>
                        <td>{{ trim(($asset['manufacturer'] ?? '').' '.($asset['model'] ?? '')) }}</td>
                        <td class="mono">{{ $asset['serial_number'] ?? '' }}</td>
                        <td class="mono">{{ $asset['asset_tag'] ?? '' }}</td>
                        <td>{{ $asset['computer_name'] ?? '' }}</td>
                        <td>{{ $asset['condition'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (filled($s['notes'] ?? null))
            <p class="hf-notes"><b>Notes:</b> {{ $s['notes'] }}</p>
        @endif

        <p class="hf-statement">
            @if ($isReturn)
                The assets listed above were returned on {{ $date($s['returned_at'] ?? null, 'Y-m-d') }}{{ filled($s['returned_by'] ?? null) ? ' by '.$s['returned_by'] : '' }} and received in the condition shown.
                The employee is no longer responsible for them from that date.
            @else
                I confirm that I have received the company assets listed above in the condition shown. I will use them for company
                work only, keep them safe, report loss or damage straight away, and return them when asked or when I leave the company.
            @endif
        </p>

        <section class="hf-signatures">
            <div class="hf-sign">
                <b>{{ $isReturn ? 'Returned by' : 'Received by (employee)' }}</b>
                <span dir="auto">{{ $isReturn ? ($s['returned_by'] ?? '') : ($employee['name'] ?? '') }}</span>
                <div class="line">Signature</div>
                <div class="line">Date</div>
            </div>
            <div class="hf-sign">
                <b>{{ $isReturn ? 'Received by (IT)' : 'Issued by (IT)' }}</b>
                <span>{{ $isReturn ? ($s['received_by'] ?? '') : ($s['issued_by'] ?? '') }}</span>
                <div class="line">Signature</div>
                <div class="line">Date</div>
            </div>
        </section>

        <footer class="hf-foot">
            <span>{{ $form->number }} · {{ count($assets) }} {{ \Illuminate\Support\Str::plural('asset', count($assets)) }}</span>
            <span>{{ $this->printNumber > 1 ? 'Copy '.$this->printNumber.' printed '.now()->format('Y-m-d H:i') : 'Printed '.now()->format('Y-m-d H:i') }}</span>
        </footer>
    </article>
</div>

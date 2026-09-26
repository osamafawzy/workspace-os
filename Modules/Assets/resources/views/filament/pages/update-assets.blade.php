{{--
    Update Assets: a scan box, the asset it found, the fields that change, and
    the asset's recent history beside them.

    Scoped styles, because the panel's compiled CSS only carries the classes
    Filament itself uses.
--}}
@php
    $asset = $this->asset();
@endphp

<x-filament-panels::page>
    <style>
        .ua { display: grid; gap: 1rem; }
        .ua-scan { position: relative; }
        .ua-scan input { width: 100%; height: 3rem; padding: 0 1rem 0 2.75rem; font-size: 1rem; border-radius: 0.75rem; border: 1px solid var(--gray-300); background: #fff; color: var(--gray-950); outline: none; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .ua-scan input:focus { border-color: var(--primary-500); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-500) 25%, transparent); }
        .ua-scan__icon { position: absolute; left: 0.875rem; top: 50%; width: 1.25rem; height: 1.25rem; transform: translateY(-50%); color: var(--gray-400); pointer-events: none; }
        .ua-hint { font-size: 0.8125rem; color: var(--gray-500); }
        .ua-body { display: grid; gap: 1rem; grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr); align-items: start; }
        @media (max-width: 64rem) { .ua-body { grid-template-columns: 1fr; } }
        .ua-card { display: grid; gap: 0.25rem; }
        .ua-card__title { font-size: 1.125rem; font-weight: 600; color: var(--gray-950); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .ua-card__meta { font-size: 0.875rem; color: var(--gray-600); }
        .ua-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
        .ua-choices { display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
        .ua-choices button { display: flex; width: 100%; justify-content: space-between; gap: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid var(--gray-200); background: #fff; text-align: left; cursor: pointer; font-size: 0.875rem; }
        .ua-choices button:hover { border-color: var(--primary-500); }
        :where(.dark) .ua-scan input, :where(.dark) .ua-choices button { background: var(--gray-900); border-color: rgb(255 255 255 / 0.15); color: #fff; }
        :where(.dark) .ua-card__title { color: #fff; }
        :where(.dark) .ua-card__meta { color: var(--gray-400); }
    </style>

    <div
        class="ua"
        x-data
        x-init="$nextTick(() => $refs.scan.focus())"
        x-on:asset-saved.window="$nextTick(() => { $refs.scan.focus() })"
    >
        <form class="ua-scan" wire:submit="find">
            <x-filament::icon icon="heroicon-o-qr-code" class="ua-scan__icon" />
            <input
                x-ref="scan"
                type="search"
                autocomplete="off"
                spellcheck="false"
                maxlength="200"
                placeholder="Scan or type a serial number, asset tag, computer name or employee OID, then Enter"
                aria-label="Serial number, asset tag, computer name or employee OID"
                wire:model="lookup"
            >
        </form>

        @if ($candidates !== [])
            <x-filament::section heading="Which one?" description="More than one asset matches.">
                <ul class="ua-choices">
                    @foreach ($this->candidateAssets() as $choice)
                        <li wire:key="choice-{{ $choice->id }}">
                            <button type="button" wire:click="open({{ $choice->id }})">
                                <span><b>{{ $choice->serial_number }}</b> · {{ $choice->assetType?->name }} {{ $choice->assetModel?->fullName() }}</span>
                                <span>{{ $choice->status->getLabel() }}@if ($choice->employee) · {{ $choice->employee->name }}@endif</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($asset)
            <div class="ua-body">
                <div class="ua">
                    <x-filament::section>
                        <div class="ua-card">
                            <span class="ua-card__title">{{ $asset->serial_number }}</span>
                            <span class="ua-card__meta">
                                {{ collect([$asset->assetType?->name, $asset->assetModel?->fullName(), $asset->asset_tag ? 'Tag '.$asset->asset_tag : null])->filter()->implode(' · ') }}
                            </span>
                            <span class="ua-card__meta">
                                <x-filament::badge :color="$asset->status->getColor()" style="display: inline-flex">{{ $asset->status->getLabel() }}</x-filament::badge>
                                @if ($asset->employee) With {{ $asset->employee->auditLabel() }} @endif
                            </span>
                        </div>
                    </x-filament::section>

                    <form wire:submit="save">
                        {{ $this->form }}

                        <div class="ua-actions">
                            <x-filament::button type="submit" icon="heroicon-m-check">Save</x-filament::button>
                            <x-filament::button tag="a" color="gray" :href="\Modules\Assets\Filament\Admin\Resources\Assets\AssetResource::getUrl('view', ['record' => $asset])">Open asset</x-filament::button>
                            <x-filament::button color="gray" wire:click="closeAsset" type="button">Close</x-filament::button>
                        </div>
                    </form>
                </div>

                <x-filament::section heading="Recent history">
                    @include('assets::filament.asset-history', ['entries' => $asset->history()->limit(10)->get()])
                </x-filament::section>
            </div>
        @elseif ($candidates === [])
            <p class="ua-hint">Tip: a barcode scanner types the label and presses Enter for you. After saving, the box is ready for the next asset.</p>
        @endif
    </div>
</x-filament-panels::page>

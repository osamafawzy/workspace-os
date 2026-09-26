{{--
    Adding New Headsets Data: the entry form, and the latest headsets under it.
    After "Save and add another" the cursor goes back to the serial number,
    ready for the next scan.
--}}
<x-filament-panels::page>
    <form
        wire:submit="save(true)"
        x-data
        x-on:headset-saved.window="$nextTick(() => document.querySelector('[data-headset-serial]')?.focus())"
    >
        {{ $this->form }}

        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem">
            <x-filament::button type="submit" icon="heroicon-m-plus">Save and add another</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="save(false)">Save</x-filament::button>
        </div>
    </form>

    <x-filament::section heading="Latest headsets">
        @php($recent = $this->recentHeadsets())

        @if ($recent->isEmpty())
            <p style="color: var(--gray-500); font-size: 0.875rem">None yet.</p>
        @else
            <ul style="display: grid; gap: 0.375rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem">
                @foreach ($recent as $headset)
                    <li wire:key="headset-{{ $headset->id }}" style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.25rem 1rem">
                        <x-filament::link :href="\Modules\Assets\Filament\Admin\Resources\Assets\AssetResource::getUrl('view', ['record' => $headset])">
                            {{ $headset->serial_number }}
                        </x-filament::link>
                        <span style="color: var(--gray-500)">
                            {{ collect([$headset->assetModel?->fullName() ?? $headset->assetType?->name, $headset->location?->name, $headset->created_at->diffForHumans()])->filter()->implode(' · ') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>

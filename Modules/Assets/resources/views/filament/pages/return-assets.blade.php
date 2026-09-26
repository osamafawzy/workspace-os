{{--
    Return Assets: what is coming back and its condition on the left, the
    search for what people hold below it, and the return details on the right.
--}}
@php
    $selectedAssets = $this->selectedAssets();
    $employee = $this->employee();
    $conditions = $this->conditionOptions();
@endphp

<x-filament-panels::page>
    @include('assets::filament.pages.partials.cart-styles')

    <div class="ac">
        <div class="ac-stack">
            <x-filament::section :heading="'Coming back · '.$selectedAssets->count()">
                @if ($selectedAssets->isEmpty())
                    <p class="ac-empty">Scan or search for the assets being returned.</p>
                @else
                    <ul class="ac-list">
                        @foreach ($selectedAssets as $asset)
                            <li class="ac-row" wire:key="selected-{{ $asset->id }}">
                                <span class="ac-row__main">
                                    <span class="ac-serial">{{ $asset->serial_number }}</span>
                                    <span class="ac-muted">{{ collect([$asset->assetType?->name, $asset->assetModel?->fullName()])->filter()->implode(' · ') }}</span>
                                    <span class="ac-muted">From {{ $asset->employee?->auditLabel() }}, since {{ $asset->assigned_at?->format('Y-m-d') }}</span>
                                </span>
                                <span class="ac-row__side">
                                    <label class="ac-muted" for="condition-{{ $asset->id }}">Condition</label>
                                    <select id="condition-{{ $asset->id }}" wire:model="selected.{{ $asset->id }}">
                                        @foreach ($conditions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" label="Remove" wire:click="remove({{ $asset->id }})" />
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section heading="Find assets" description="Everything somebody holds. Scan a label, search, or pick an employee to list theirs.">
                <form class="ac-scan" wire:submit="scan" x-data x-init="$nextTick(() => $refs.search.focus())">
                    <x-filament::icon icon="heroicon-o-qr-code" class="ac-scan__icon" />
                    <input x-ref="search" type="search" autocomplete="off" maxlength="200" wire:model.live.debounce.300ms="search" placeholder="Scan a serial or tag, or search by employee name, OID, model…" aria-label="Find assets being returned">
                </form>

                @if ($employee)
                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-top: 0.75rem">
                        <x-filament::badge color="info">Held by {{ $employee->auditLabel() }}</x-filament::badge>
                        <x-filament::button size="sm" color="gray" wire:click="addAllFromEmployee">Add everything they hold</x-filament::button>
                        <x-filament::link tag="button" size="sm" wire:click="forEmployee(null)">Show everybody's</x-filament::link>
                    </div>
                @endif

                <ul class="ac-list" style="margin-top: 0.75rem">
                    @forelse ($this->heldAssets() as $asset)
                        <li class="ac-row" wire:key="held-{{ $asset->id }}">
                            <span class="ac-row__main">
                                <span class="ac-serial">{{ $asset->serial_number }}</span>
                                <span class="ac-muted">{{ collect([$asset->assetType?->name, $asset->assetModel?->fullName()])->filter()->implode(' · ') }}</span>
                                <span class="ac-muted">
                                    With
                                    <x-filament::link tag="button" size="sm" wire:click="forEmployee({{ $asset->employee_id }})">{{ $asset->employee?->auditLabel() }}</x-filament::link>
                                </span>
                            </span>
                            <x-filament::button size="sm" color="gray" icon="heroicon-m-plus" wire:click="add({{ $asset->id }})">Add</x-filament::button>
                        </li>
                    @empty
                        <li class="ac-empty">{{ $employee || trim($search) !== '' ? 'Nothing held matches.' : 'Search, scan or pick an employee to see what they hold.' }}</li>
                    @endforelse
                </ul>
            </x-filament::section>
        </div>

        <div class="ac-stack">
            <x-filament::section heading="Return details">
                {{ $this->form }}

                <div style="margin-top: 1rem">
                    <x-filament::button wire:click="recordReturn" icon="heroicon-m-arrow-uturn-left" :disabled="$selectedAssets->isEmpty()" wire:loading.attr="disabled" wire:target="recordReturn">
                        Return {{ $selectedAssets->count() }} and print the receipt
                    </x-filament::button>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

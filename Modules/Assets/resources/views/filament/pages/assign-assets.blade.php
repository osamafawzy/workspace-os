{{--
    Assign Assets: the employee and what they hold on the right, the assets
    being handed over and the search to find more on the left.
--}}
@php
    $employee = $this->employee();
    $selectedAssets = $this->selectedAssets();
@endphp

<x-filament-panels::page>
    @include('assets::filament.pages.partials.cart-styles')

    <div class="ac">
        <div class="ac-stack">
            <x-filament::section :heading="'Handing over · '.$selectedAssets->count()">
                @if ($selectedAssets->isEmpty())
                    <p class="ac-empty">Scan or search for assets below to add them.</p>
                @else
                    <ul class="ac-list">
                        @foreach ($selectedAssets as $asset)
                            <li class="ac-row" wire:key="selected-{{ $asset->id }}">
                                <span class="ac-row__main">
                                    <span class="ac-serial">{{ $asset->serial_number }}</span>
                                    <span class="ac-muted">{{ collect([$asset->assetType?->name, $asset->assetModel?->fullName(), $asset->asset_tag ? 'Tag '.$asset->asset_tag : null])->filter()->implode(' · ') }}</span>
                                </span>
                                <span class="ac-row__side">
                                    <x-filament::badge :color="$asset->condition?->getColor() ?? 'gray'">{{ $asset->condition?->getLabel() ?? 'Condition not recorded' }}</x-filament::badge>
                                    <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" label="Remove" wire:click="remove({{ $asset->id }})" />
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section heading="Add assets" description="In stock and returned assets nobody holds.">
                <form class="ac-scan" wire:submit="scan" x-data x-init="$nextTick(() => $refs.search.focus())">
                    <x-filament::icon icon="heroicon-o-qr-code" class="ac-scan__icon" />
                    <input x-ref="search" type="search" autocomplete="off" maxlength="200" wire:model.live.debounce.300ms="search" placeholder="Scan a serial or tag, or search type, model, location…" aria-label="Find assets to hand over">
                </form>

                <ul class="ac-list" style="margin-top: 0.75rem">
                    @forelse ($this->availableAssets() as $asset)
                        <li class="ac-row" wire:key="available-{{ $asset->id }}">
                            <span class="ac-row__main">
                                <span class="ac-serial">{{ $asset->serial_number }}</span>
                                <span class="ac-muted">{{ collect([$asset->assetType?->name, $asset->assetModel?->fullName(), $asset->location?->name, $asset->status->getLabel()])->filter()->implode(' · ') }}</span>
                            </span>
                            <x-filament::button size="sm" color="gray" icon="heroicon-m-plus" wire:click="add({{ $asset->id }})">Add</x-filament::button>
                        </li>
                    @empty
                        <li class="ac-empty">No free asset matches.</li>
                    @endforelse
                </ul>
            </x-filament::section>
        </div>

        <div class="ac-stack">
            <x-filament::section heading="Employee">
                {{ $this->form }}

                @if ($employee)
                    <dl class="ac-facts" style="margin-top: 1rem">
                        <dt>OID</dt><dd>{{ $employee->oid }}</dd>
                        <dt>Job</dt><dd>{{ $employee->job_title ?? '—' }}</dd>
                        <dt>Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd>
                        <dt>Where</dt><dd>{{ collect([$employee->site?->name, $employee->location?->name])->filter()->implode(' · ') ?: '—' }}</dd>
                        <dt>Status</dt><dd><x-filament::badge :color="$employee->status->getColor()" style="display: inline-flex">{{ $employee->status->getLabel() }}</x-filament::badge></dd>
                    </dl>

                    <div style="margin-top: 1rem">
                        <x-filament::button wire:click="assign" icon="heroicon-m-check" :disabled="$selectedAssets->isEmpty()" wire:loading.attr="disabled" wire:target="assign">
                            Assign {{ $selectedAssets->count() }} and print the form
                        </x-filament::button>
                    </div>
                @endif
            </x-filament::section>

            @if ($employee)
                <x-filament::section :heading="'Holds now · '.$this->heldAssets()->count()">
                    <ul class="ac-list">
                        @forelse ($this->heldAssets() as $asset)
                            <li class="ac-row" wire:key="held-{{ $asset->id }}">
                                <span class="ac-row__main">
                                    <span class="ac-serial">{{ $asset->serial_number }}</span>
                                    <span class="ac-muted">{{ collect([$asset->assetType?->name, $asset->assetModel?->fullName()])->filter()->implode(' · ') }} · since {{ $asset->assigned_at?->format('Y-m-d') }}</span>
                                </span>
                            </li>
                        @empty
                            <li class="ac-empty">Nothing.</li>
                        @endforelse
                    </ul>
                </x-filament::section>

                <x-filament::section heading="Assignment history" collapsible collapsed>
                    <ul class="ac-list">
                        @forelse ($this->recentAssignments() as $assignment)
                            <li class="ac-row" wire:key="history-{{ $assignment->id }}">
                                <span class="ac-row__main">
                                    <span class="ac-serial">{{ $assignment->asset?->serial_number }}</span>
                                    <span class="ac-muted">
                                        {{ $assignment->assigned_at->format('Y-m-d') }} → {{ $assignment->returned_at?->format('Y-m-d') ?? 'still with them' }}
                                    </span>
                                </span>
                                @if ($assignment->handoverForm && auth()->user()->can('print', $assignment->handoverForm))
                                    <x-filament::link :href="\Modules\Assets\Filament\Admin\Pages\PrintHandoverForm::getUrl(['form' => $assignment->handoverForm->id])" size="sm">{{ $assignment->handoverForm->number }}</x-filament::link>
                                @endif
                            </li>
                        @empty
                            <li class="ac-empty">Never been assigned anything.</li>
                        @endforelse
                    </ul>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>

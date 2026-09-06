@extends('publicsite::layouts.app', ['navigationFloors' => $floors])

@section('title', config('app.name').' · The building')
@section('description', 'Every floor in the building, stacked, with each workstation where it physically sits.')

@push('head')
    @vite('Modules/PublicSite/resources/js/building.js')
@endpush

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12">
        <div class="max-w-2xl">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">The building</h1>
            <p class="mt-3 text-slate-600 dark:text-zinc-400">
                {{ $floors->count() }} {{ Str::plural('floor', $floors->count()) }},
                {{ $floors->sum('workstations_count') }} {{ Str::plural('workstation', $floors->sum('workstations_count')) }}.
                Drag to orbit, scroll to zoom, and click a floor to bring it forward &mdash;
                then click a desk on it for everything recorded about that workstation.
            </p>
        </div>

        @if ($floors->isEmpty())
            <div class="mt-8 rounded-xl border border-dashed border-slate-300 p-12 text-center text-slate-500 dark:border-zinc-700 dark:text-zinc-400">
                No floors have been added yet.
            </div>
        @else
            <div
                data-building
                class="mt-8 grid gap-6 lg:grid-cols-[1fr_18rem]"
            >
                {{-- The scene payload. A script tag rather than a data attribute:
                     the desk list can run to hundreds of entries and JSON in an
                     attribute has to be HTML-escaped character by character. --}}
                <script type="application/json" data-scene>{!! \Modules\PublicSite\Support\BuildingGeometry::json($scene) !!}</script>

                <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-slate-100 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div data-canvas class="h-[26rem] w-full sm:h-[34rem] lg:h-[40rem]"></div>

                    <div
                        data-tooltip
                        hidden
                        class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-[calc(100%+0.75rem)] whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white shadow-lg dark:bg-zinc-100 dark:text-zinc-900"
                    ></div>

                    <div
                        data-webgl-missing
                        hidden
                        class="p-10 text-center text-sm text-slate-600 dark:text-zinc-400"
                    >
                        This browser cannot draw 3D graphics. Every floor is listed beside this &mdash;
                        open one for its flat plan.
                    </div>

                    <div
                        data-viewer-controls
                        class="pointer-events-none absolute inset-x-0 bottom-0 flex flex-wrap items-center gap-3 bg-gradient-to-t from-slate-100 to-transparent p-4 dark:from-zinc-900"
                    >
                        <div class="pointer-events-auto flex items-center gap-2.5 rounded-lg border border-slate-200 bg-white/90 px-3 py-2 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-800/90">
                            <label for="explode" class="text-xs font-medium text-slate-600 dark:text-zinc-400">Spread</label>
                            <input
                                id="explode"
                                data-explode
                                type="range"
                                min="1"
                                max="3"
                                step="0.05"
                                value="1"
                                class="h-1 w-28 cursor-pointer appearance-none rounded-full bg-slate-300 accent-indigo-600 dark:bg-zinc-600"
                            >
                        </div>

                        <button
                            type="button"
                            data-reset
                            class="pointer-events-auto rounded-lg border border-slate-200 bg-white/90 px-3 py-2 text-xs font-medium shadow-sm backdrop-blur transition hover:bg-white dark:border-zinc-700 dark:bg-zinc-800/90 dark:hover:bg-zinc-800"
                        >Reset view</button>

                        <div class="pointer-events-auto ml-auto flex items-center gap-2">
                            <span data-focus-label class="text-xs font-medium text-slate-600 dark:text-zinc-400"></span>
                            <button
                                type="button"
                                data-clear-focus
                                hidden
                                class="rounded-lg border border-slate-200 bg-white/90 px-3 py-2 text-xs font-medium shadow-sm backdrop-blur transition hover:bg-white dark:border-zinc-700 dark:bg-zinc-800/90 dark:hover:bg-zinc-800"
                            >Show all floors</button>
                        </div>
                    </div>
                </div>

                {{-- Top of the building first here, because this list sits beside
                     a picture of the building and should read the same way up. --}}
                <ol class="flex flex-col gap-2">
                    @foreach ($floors->sortByDesc('level') as $floor)
                        <li class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition data-[active=true]:border-indigo-500 dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-baseline justify-between gap-3">
                                <button
                                    type="button"
                                    data-floor-button="{{ $floor->id }}"
                                    class="text-left font-medium tracking-tight transition hover:text-indigo-600 data-[active=true]:text-indigo-600 dark:hover:text-indigo-400 dark:data-[active=true]:text-indigo-400"
                                >{{ $floor->name }}</button>

                                <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-500 dark:bg-zinc-800 dark:text-zinc-400">
                                    L{{ $floor->level }}
                                </span>
                            </div>

                            <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                                {{ $floor->workstations_count }} {{ Str::plural('workstation', $floor->workstations_count) }}
                                @if ($floor->placed_workstations_count < $floor->workstations_count)
                                    <span class="text-slate-400 dark:text-zinc-500">
                                        &middot; {{ $floor->workstations_count - $floor->placed_workstations_count }} not yet on the plan
                                    </span>
                                @endif
                            </p>

                            <a
                                href="{{ route('building.floor', $floor) }}"
                                class="mt-2.5 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                            >
                                Flat plan
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>

    @include('publicsite::partials.desk-modal')
@endsection

@extends('publicsite::layouts.app', ['navigationFloors' => $otherFloors])

@section('title', $floor->name.' · '.config('app.name'))
@section('description', $floor->name.' — where each workstation sits on this floor.')

@push('head')
    @vite('Modules/PublicSite/resources/js/floor.js')
@endpush

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12">
        <nav class="flex items-center gap-2 text-sm text-slate-500 dark:text-zinc-400" aria-label="Breadcrumb">
            <a href="{{ route('building') }}" class="transition hover:text-slate-900 dark:hover:text-zinc-100">The building</a>
            <span aria-hidden="true">/</span>
            <span class="text-slate-900 dark:text-zinc-100">{{ $floor->name }}</span>
        </nav>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $floor->name }}</h1>
                <p class="mt-2 text-slate-600 dark:text-zinc-400">
                    Level {{ $floor->level }} &middot;
                    {{ number_format($floor->width_m) }} &times; {{ number_format($floor->depth_m) }} m &middot;
                    {{ number_format($desks->count()) }} {{ Str::plural('workstation', $desks->count()) }}
                    @if (count($placed) < $desks->count())
                        &middot; {{ $desks->count() - count($placed) }} not yet on the plan
                    @endif
                </p>
                @if (filled($floor->description))
                    <p class="mt-2 max-w-2xl text-slate-600 dark:text-zinc-400">{{ $floor->description }}</p>
                @endif
            </div>
        </div>

        {{-- Every desk on this floor, grouped and labelled server-side. A
             script tag rather than an attribute per pin: the same desk is on
             the page twice, and three hundred escaped JSON attributes is a lot
             of bytes to say the same thing. --}}
        <script type="application/json" data-desks>{!! \Modules\PublicSite\Support\BuildingGeometry::json($deskData) !!}</script>

        <p class="mt-6 text-sm text-slate-500 dark:text-zinc-400">
            Click a workstation &mdash; on the plan or in the list &mdash; for everything recorded about it.
        </p>

        <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_16rem]">
            {{-- The flat plan. Read-only: same coordinate space as the admin
                 editor, no drag handlers, and the pins are plain markers. --}}
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                @if (count($placed) === 0)
                    <div class="p-12 text-center text-sm text-slate-500 dark:text-zinc-400">
                        No workstation on this floor has been positioned yet.
                    </div>
                @else
                    @php
                        // A five-metre lattice, from the floor's real size. On a
                        // 60 m floor a "10%" grid line would be six metres apart
                        // and mean nothing; five metres is a distance somebody
                        // can pace out.
                        $gridX = 100 / max($floor->width_m / 5, 1);
                        $gridY = 100 / max($floor->depth_m / 5, 1);
                        // Compact pins once names would collide. 40 is about
                        // where a floor of badges stops being readable.
                        $compact = count($placed) > 40;

                        // The shape to draw at. The drawing's own, when there
                        // is one that can be measured, so the markers are not
                        // laid out against a zero-height box and then thrown
                        // down the page the moment the image arrives. Null for
                        // an SVG, whose size is not in a readable header — that
                        // one keeps taking its height from the image itself,
                        // which is what kept the markers aligned before.
                        $shape = $planImage ? $floor->planAspectRatio() : $floor->width_m.' / '.$floor->depth_m;
                    @endphp

                    <div
                        class="ws-floor relative w-full bg-white dark:bg-zinc-900 @if (! $planImage) ws-floor--grid @endif"
                        @style([
                            'aspect-ratio: '.$shape => $shape !== null,
                            '--grid-x: '.$gridX.'%',
                            '--grid-y: '.$gridY.'%',
                        ])
                        data-compact="{{ $compact ? 'true' : 'false' }}"
                    >
                        @if ($planImage)
                            <img src="{{ $planImage }}" alt="{{ $floor->name }} floor plan" class="block w-full">
                        @endif

                        @foreach ($placed as $desk)
                            <button
                                type="button"
                                data-desk="{{ $desk['id'] }}"
                                class="ws-pin absolute -translate-x-1/2 -translate-y-1/2"
                                title="{{ $desk['name'] }}"
                                aria-label="{{ $desk['name'] }} — open its details"
                                style="left: {{ $desk['x'] }}%; top: {{ $desk['y'] }}%;"
                            >
                                <span class="ws-pin__icon" aria-hidden="true"></span>
                                <span class="ws-pin__name">{{ $desk['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">Workstations</h2>

                <ul class="mt-3 flex max-h-[32rem] flex-col gap-1.5 overflow-y-auto pr-1">
                    @forelse ($desks as $desk)
                        <li>
                            <button
                                type="button"
                                data-desk="{{ $desk->id }}"
                                class="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-sm transition hover:border-indigo-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-indigo-500"
                            >
                                <span class="font-medium">{{ $desk->name }}</span>

                                @if ($desk->hasDetails())
                                    <span class="shrink-0 text-xs text-indigo-600 dark:text-indigo-400">details</span>
                                @elseif ($desk->isPlaced())
                                    <span class="shrink-0 font-mono text-xs text-slate-400 dark:text-zinc-500">
                                        {{ $desk->position_x }}, {{ $desk->position_y }}
                                    </span>
                                @else
                                    <span class="shrink-0 text-xs text-slate-400 dark:text-zinc-500">not placed</span>
                                @endif
                            </button>
                        </li>
                    @empty
                        <li class="rounded-lg border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-500 dark:border-zinc-700 dark:text-zinc-400">
                            This floor has no workstations yet.
                        </li>
                    @endforelse
                </ul>

                @if ($otherFloors->isNotEmpty())
                    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">Other floors</h2>

                    <ul class="mt-3 flex flex-col gap-1.5">
                        @foreach ($otherFloors->sortByDesc('level') as $other)
                            <li>
                                <a
                                    href="{{ route('building.floor', $other) }}"
                                    class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm transition hover:border-indigo-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-indigo-500"
                                >
                                    <span class="font-medium">{{ $other->name }}</span>
                                    <span class="text-xs text-slate-400 dark:text-zinc-500">
                                        {{ $other->workstations_count }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    @include('publicsite::partials.desk-modal')
@endsection

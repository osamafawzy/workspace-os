<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', 'Every floor in the building and where each workstation sits.')">

    {{-- The theme is read before paint so a dark-mode viewer never gets a
         white flash, and the 3D scene picks its palette from the same class. --}}
    <script>
        (() => {
            try {
                const stored = localStorage.getItem('workspace-theme')
                const dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches
                document.documentElement.classList.toggle('dark', dark)
            } catch { /* private mode, storage unavailable — fall through to light */ }
        })()
    </script>

    {{-- Three.js is ~140 KB gzipped. It is pushed by the one page that draws a
         canvas rather than loaded here, so the flat floor pages — which are
         also the no-WebGL fallback — never pay for a renderer they do not use. --}}
    @vite(['resources/css/app.css'])
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-slate-50/80 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80">
        <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3.5 sm:px-6">
            <a href="{{ route('building') }}" class="flex items-center gap-2.5 font-semibold tracking-tight">
                <span class="grid size-7 place-items-center rounded-md bg-indigo-600 text-[0.7rem] font-bold text-white">WS</span>
                {{ config('app.name') }}
            </a>

            <nav class="ml-auto flex items-center gap-1 text-sm">
                @foreach ($navigationFloors ?? [] as $navFloor)
                    <a
                        href="{{ route('building.floor', $navFloor) }}"
                        class="hidden rounded-md px-2.5 py-1.5 text-slate-600 transition hover:bg-slate-200/70 hover:text-slate-900 sm:block dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    >{{ $navFloor->name }}</a>
                @endforeach

                <button
                    type="button"
                    class="ml-1 grid size-8 place-items-center rounded-md text-slate-600 transition hover:bg-slate-200/70 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    aria-label="Switch between light and dark"
                    onclick="
                        const dark = document.documentElement.classList.toggle('dark');
                        try { localStorage.setItem('workspace-theme', dark ? 'dark' : 'light') } catch {}
                    "
                >
                    <svg class="size-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <svg class="hidden size-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke-linecap="round"/>
                    </svg>
                </button>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 py-10 text-sm text-slate-500 sm:px-6 dark:text-zinc-500">
        A read-only view of the building. Nothing on these pages can be changed.
    </footer>
</body>
</html>

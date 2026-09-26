{{-- The assets an employee holds, on their profile. --}}
@php($assets = collect($getState()))

<style>
    .ea-list { display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem; }
    .ea-list li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.25rem 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gray-100); }
    .ea-list a { font-weight: 600; color: var(--primary-600); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .ea-muted { color: var(--gray-500); }
    :where(.dark) .ea-list a { color: var(--primary-400); }
    :where(.dark) .ea-muted { color: var(--gray-400); }
    :where(.dark) .ea-list li { border-color: rgb(255 255 255 / 0.08); }
</style>

@if ($assets->isEmpty())
    <p class="ea-muted">Holds no assets.</p>
@else
    <ul class="ea-list">
        @foreach ($assets as $asset)
            <li>
                <span>
                    <a href="{{ $asset['url'] }}">{{ $asset['serial'] }}</a>
                    <span class="ea-muted">{{ $asset['label'] }}@if ($asset['tag']) · Tag {{ $asset['tag'] }}@endif</span>
                </span>
                <span class="ea-muted">
                    {{ $asset['status']->getLabel() }}@if ($asset['since']) · since {{ $asset['since'] }}@endif
                </span>
            </li>
        @endforeach
    </ul>
@endif

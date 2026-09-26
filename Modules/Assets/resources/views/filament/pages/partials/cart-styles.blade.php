{{-- Shared by Assign Assets and Return Assets. Scoped: the panel's compiled CSS only carries Filament's own classes. --}}
<style>
    .ac { display: grid; gap: 1rem; grid-template-columns: minmax(0, 3fr) minmax(18rem, 2fr); align-items: start; }
    @media (max-width: 64rem) { .ac { grid-template-columns: 1fr; } }
    .ac-stack { display: grid; gap: 1rem; min-width: 0; }
    .ac-scan { position: relative; }
    .ac-scan input { width: 100%; height: 2.75rem; padding: 0 1rem 0 2.5rem; border-radius: 0.625rem; border: 1px solid var(--gray-300); background: #fff; color: var(--gray-950); outline: none; font-size: 0.9375rem; }
    .ac-scan input:focus { border-color: var(--primary-500); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-500) 25%, transparent); }
    .ac-scan__icon { position: absolute; left: 0.75rem; top: 50%; width: 1.125rem; height: 1.125rem; transform: translateY(-50%); color: var(--gray-400); pointer-events: none; }
    .ac-list { display: grid; gap: 0.375rem; margin: 0; padding: 0; list-style: none; }
    .ac-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem 1rem; padding: 0.625rem 0.75rem; border-radius: 0.5rem; border: 1px solid var(--gray-200); background: #fff; font-size: 0.875rem; }
    .ac-row__main { display: grid; gap: 0.125rem; min-width: 0; }
    .ac-serial { font-weight: 600; color: var(--gray-950); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .ac-muted { color: var(--gray-500); font-size: 0.8125rem; }
    .ac-row__side { display: flex; align-items: center; gap: 0.5rem; }
    .ac-row select { height: 2rem; padding: 0 0.5rem; border-radius: 0.375rem; border: 1px solid var(--gray-300); background: #fff; font-size: 0.8125rem; }
    .ac-empty { padding: 1rem; text-align: center; color: var(--gray-500); font-size: 0.875rem; }
    .ac-facts { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; margin: 0; font-size: 0.875rem; }
    .ac-facts dt { color: var(--gray-500); }
    .ac-facts dd { margin: 0; color: var(--gray-950); }
    .ac-count { font-weight: 600; }
    :where(.dark) .ac-scan input, :where(.dark) .ac-row, :where(.dark) .ac-row select { background: var(--gray-900); border-color: rgb(255 255 255 / 0.12); color: #fff; }
    :where(.dark) .ac-serial, :where(.dark) .ac-facts dd { color: #fff; }
    :where(.dark) .ac-muted, :where(.dark) .ac-facts dt, :where(.dark) .ac-empty { color: var(--gray-400); }
</style>

{{--
    The floor, drawn.

    Built for a floor that holds hundreds of desks, which changes three things
    from the obvious version:

      - pointer and key handling is delegated from the surface rather than
        bound per pin, so three hundred desks do not mean three hundred sets of
        listeners;
      - a pin is a dot by default and only shows its name on hover or when
        selected, because three hundred name badges on one plan is a solid
        block of text;
      - the tray of unplaced desks is searchable and capped, since a list of
        three hundred chips is not something anyone reads.

    Everything interactive lives in one Alpine component that owns the desk
    list outright, so a drag is instant and only the settled coordinate goes
    back to the server. The markup is styled with a scoped stylesheet rather
    than utility classes because a Filament panel compiles its own CSS and
    would not include classes it has never seen.
--}}
<x-filament-panels::page>
    @php
        $floor = $this->getRecord();
        $desks = $this->planDesks();
        $planImage = $this->planImageUrl();

        // Without the arrange permission the plan is something to look at:
        // pins open their details, nothing drags, and the tools that move
        // desks are not shown. The server refuses those writes regardless.
        $editable = $this->canArrange();

        // The shape to draw at: the drawing's own when there is one that can be
        // measured, the floor's metres when there is not. Null for an SVG plan,
        // whose size is not in a header getimagesize can read — that one keeps
        // taking its height from the image, which is what held the pins in the
        // right place before any of this.
        $shape = $planImage ? $floor->planAspectRatio() : $floor->width_m.' / '.$floor->depth_m;

        // The window has to have a height whatever happens, even when the
        // shape of what is inside it is unknown.
        $frame = $shape ?: $floor->width_m.' / '.$floor->depth_m;
    @endphp

    <style>
        .ws-plan {
            --ws-surface: #ffffff;
            --ws-ink: #0f172a;
            --ws-muted: #64748b;
            --ws-line: #e2e8f0;
            --ws-grid: #eef2f7;
            --ws-grid-strong: #dbe3ec;
            --ws-accent: #4f46e5;
            --ws-accent-ink: #ffffff;
            --ws-chip: #f1f5f9;
            --ws-shadow: 0 1px 2px rgb(15 23 42 / 0.08), 0 4px 12px rgb(15 23 42 / 0.06);

            /* A desk is drawn as a computer. One masked glyph shared by every
               pin rather than an <svg> per pin: on a three-hundred-desk floor
               that is three hundred extra subtrees to build, diff and paint. */
            --ws-monitor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M5.5 4h13A1.5 1.5 0 0 1 20 5.5v9A1.5 1.5 0 0 1 18.5 16h-5.75v2h2.75a.75.75 0 0 1 0 1.5h-7a.75.75 0 0 1 0-1.5h2.75v-2H5.5A1.5 1.5 0 0 1 4 14.5v-9A1.5 1.5 0 0 1 5.5 4Z'/%3E%3C/svg%3E");
            --ws-pin-size: 1rem;
        }

        :where(.dark) .ws-plan {
            --ws-surface: #18181b;
            --ws-ink: #f4f4f5;
            --ws-muted: #a1a1aa;
            --ws-line: #3f3f46;
            --ws-grid: #232327;
            --ws-grid-strong: #2f2f35;
            --ws-accent: #818cf8;
            --ws-accent-ink: #1e1b4b;
            --ws-chip: #27272a;
            --ws-shadow: 0 1px 2px rgb(0 0 0 / 0.4), 0 4px 12px rgb(0 0 0 / 0.3);
        }

        .ws-plan { color: var(--ws-ink); }

        .ws-plan__bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.75rem;
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.75rem;
            box-shadow: var(--ws-shadow);
        }

        .ws-plan__group { display: flex; align-items: center; gap: 0.375rem; }
        .ws-plan__spacer { flex: 1 1 auto; }

        .ws-plan__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            min-width: 2rem;
            height: 2rem;
            padding: 0 0.625rem;
            font-size: 0.8125rem;
            font-weight: 500;
            line-height: 1;
            color: var(--ws-ink);
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.5rem;
            cursor: pointer;
            transition: background-color 120ms, border-color 120ms;
        }

        .ws-plan__btn:hover:not(:disabled) { background: var(--ws-chip); }
        .ws-plan__btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .ws-plan__btn:focus-visible { outline: 2px solid var(--ws-accent); outline-offset: 2px; }
        .ws-plan__btn[aria-pressed="true"] {
            background: var(--ws-accent);
            border-color: var(--ws-accent);
            color: var(--ws-accent-ink);
        }

        .ws-plan__meta {
            font-size: 0.8125rem;
            color: var(--ws-muted);
            font-variant-numeric: tabular-nums;
        }

        .ws-plan__viewport {
            position: relative;
            overflow: hidden;
            max-height: 74vh;
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.75rem;
            box-shadow: var(--ws-shadow);
            touch-action: none;
            cursor: grab;
        }

        .ws-plan__viewport[data-panning="true"] { cursor: grabbing; }

        .ws-plan__stage {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            transform-origin: 0 0;
            will-change: transform;
        }

        .ws-plan__surface {
            position: relative;
            width: 100%;
            background-color: var(--ws-surface);
            box-shadow: inset 0 0 0 1px var(--ws-line);
        }

        /* A metre lattice: the fine line is one metre, the strong one every
           five. Both are set from the floor's real dimensions, so the grid
           means something rather than being decoration. */
        .ws-plan__surface--blank {
            background-image:
                linear-gradient(to right, var(--ws-grid-strong) 1px, transparent 1px),
                linear-gradient(to bottom, var(--ws-grid-strong) 1px, transparent 1px),
                linear-gradient(to right, var(--ws-grid) 1px, transparent 1px),
                linear-gradient(to bottom, var(--ws-grid) 1px, transparent 1px);
        }

        .ws-plan__image { display: block; width: 100%; height: auto; }

        .ws-plan__pin {
            position: absolute;
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.5rem 0.25rem 0.375rem;
            font: 500 0.75rem/1 ui-sans-serif, system-ui, sans-serif;
            color: var(--ws-ink);
            white-space: nowrap;
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 999px;
            box-shadow: var(--ws-shadow);
            transform: translate(-50%, -50%);
            cursor: grab;
            touch-action: none;
            user-select: none;
        }

        /* The marker itself: a tile carrying a monitor glyph. The tile is a
           plain element and the glyph is its masked ::before, which is what
           lets the tile keep a colour and a ring — a mask clips everything
           painted through it, a box-shadow on the glyph included. */
        .ws-plan__pin-icon {
            position: relative;
            flex: none;
            width: var(--ws-pin-size);
            height: var(--ws-pin-size);
            border-radius: 0.3125rem;
            /* Grey until somebody has traced the desk. Colour rather than the
               ring this used to draw, because at three hundred desks "which
               ones are done" has to be answerable from across the room, and a
               1px ring at that density is not. */
            background: var(--ws-muted);
        }

        .ws-plan__pin-icon::before {
            content: "";
            position: absolute;
            inset: 0.125rem;
            background: var(--ws-accent-ink);
            -webkit-mask: var(--ws-monitor) center / contain no-repeat;
            mask: var(--ws-monitor) center / contain no-repeat;
        }

        .ws-plan__pin[data-details="true"] .ws-plan__pin-icon { background: var(--ws-accent); }

        .ws-plan__pin:focus-visible { outline: 2px solid var(--ws-accent); outline-offset: 2px; }
        .ws-plan__pin[data-selected="true"] {
            border-color: var(--ws-accent);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--ws-accent) 25%, transparent), var(--ws-shadow);
            z-index: 20;
        }

        /* A compact pin has no pill to outline, so selection lands on the tile
           and reads the same in both modes. */
        .ws-plan__pin[data-selected="true"] .ws-plan__pin-icon {
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--ws-accent) 35%, transparent);
        }

        /* Compact: the tile on its own, with the pill around it taken away.
           No box-shadow — three hundred shadowed pins is a measurable amount of
           paint on every pan. */
        .ws-plan[data-labels="false"] .ws-plan__pin {
            padding: 0;
            gap: 0;
            border: 0;
            background: none;
            border-radius: 0.3125rem;
            box-shadow: none;
        }

        /* The name is taken out of flow so revealing it cannot nudge the tile
           off the spot it is marking. */
        .ws-plan[data-labels="false"] .ws-plan__pin > .ws-plan__pin-name {
            position: absolute;
            left: 100%;
            top: 50%;
            transform: translateY(-50%);
            margin-left: 0.375rem;
            padding: 0.125rem 0.375rem;
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.25rem;
            box-shadow: var(--ws-shadow);
            opacity: 0;
            pointer-events: none;
            transition: opacity 90ms;
        }

        .ws-plan[data-labels="false"] .ws-plan__pin:hover > .ws-plan__pin-name,
        .ws-plan[data-labels="false"] .ws-plan__pin:focus-visible > .ws-plan__pin-name,
        .ws-plan[data-labels="false"] .ws-plan__pin[data-selected="true"] > .ws-plan__pin-name { opacity: 1; }

        .ws-plan[data-labels="false"] .ws-plan__pin:hover,
        .ws-plan[data-labels="false"] .ws-plan__pin[data-selected="true"] { z-index: 20; }

        .ws-plan__tray {
            margin-top: 0.75rem;
            padding: 0.75rem 1rem;
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.75rem;
            box-shadow: var(--ws-shadow);
        }

        .ws-plan__tray-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .ws-plan__tray-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--ws-muted);
        }

        .ws-plan__search {
            height: 2rem;
            padding: 0 0.625rem;
            font-size: 0.8125rem;
            color: var(--ws-ink);
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.5rem;
            min-width: 10rem;
        }

        .ws-plan__search:focus-visible { outline: 2px solid var(--ws-accent); outline-offset: 1px; }

        .ws-plan__chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            margin-top: 0.625rem;
            max-height: 7rem;
            overflow-y: auto;
        }

        .ws-plan__chip {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            font: 500 0.75rem/1.4 ui-sans-serif, system-ui, sans-serif;
            color: var(--ws-ink);
            background: var(--ws-chip);
            border: 1px dashed var(--ws-line);
            border-radius: 999px;
            cursor: grab;
            touch-action: none;
            user-select: none;
        }

        .ws-plan__chip:focus-visible { outline: 2px solid var(--ws-accent); outline-offset: 2px; }

        /* The rectangle being drawn over a bank of desks. Inside the surface
           and sized in percentages, so it pans and zooms with the drawing
           underneath it without any of that being worked out here. */
        .ws-plan__box {
            position: absolute;
            z-index: 30;
            pointer-events: none;
            border: 1px solid var(--ws-accent);
            border-radius: 0.25rem;
            background: color-mix(in srgb, var(--ws-accent) 12%, transparent);
        }

        .ws-plan__viewport[data-mode="fill"] { cursor: crosshair; }

        /* Anchored to where the box was finished rather than docked to the
           toolbar: the numbers being typed are about that rectangle, and
           reading them a screen away from it is guesswork. */
        .ws-plan__fill {
            position: fixed;
            z-index: 70;
            width: 15rem;
            padding: 0.75rem;
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.625rem;
            box-shadow: var(--ws-shadow);
        }

        .ws-plan__fill-row {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            margin-bottom: 0.5rem;
        }

        .ws-plan__fill input {
            width: 3.5rem;
            padding: 0.25rem 0.375rem;
            font-size: 0.8125rem;
            color: var(--ws-ink);
            background: var(--ws-surface);
            border: 1px solid var(--ws-line);
            border-radius: 0.375rem;
        }

        .ws-plan__ghost {
            position: fixed;
            z-index: 60;
            pointer-events: none;
            transform: translate(-50%, -50%);
            opacity: 0.9;
        }

        .ws-plan__hint {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--ws-muted);
        }

        .ws-plan__empty { font-size: 0.8125rem; color: var(--ws-muted); }
    </style>

    <div
        class="ws-plan"
        wire:ignore
        x-data="workspaceFloorPlan(@js($desks), @js([
            'width' => $floor->width_m,
            'depth' => $floor->depth_m,
            'capacity' => $floor->deskCapacity(),
            'editable' => $editable,
        ]))"
        x-bind:data-labels="labels ? 'true' : 'false'"
    >
        <div class="ws-plan__bar">
            <div class="ws-plan__group">
                <button type="button" class="ws-plan__btn" x-on:click="zoomBy(1 / 1.25)" title="Zoom out" aria-label="Zoom out">&minus;</button>
                <span class="ws-plan__meta" style="min-width: 3rem; text-align: center;" x-text="Math.round(zoom * 100) + '%'"></span>
                <button type="button" class="ws-plan__btn" x-on:click="zoomBy(1.25)" title="Zoom in" aria-label="Zoom in">+</button>
                <button type="button" class="ws-plan__btn" x-on:click="resetView()">Fit</button>
            </div>

            <div class="ws-plan__group">
                <button
                    type="button"
                    class="ws-plan__btn"
                    x-on:click="snap = !snap"
                    x-bind:aria-pressed="snap ? 'true' : 'false'"
                >Snap</button>

                <select class="ws-plan__btn" x-model.number="step" x-bind:disabled="! snap" aria-label="Grid size">
                    <option value="0.5">0.5 m</option>
                    <option value="1">1 m</option>
                    <option value="2">2 m</option>
                    <option value="5">5 m</option>
                </select>

                <button
                    type="button"
                    class="ws-plan__btn"
                    x-on:click="labels = !labels"
                    x-bind:aria-pressed="labels ? 'true' : 'false'"
                    title="Show every desk name, rather than only the one under the pointer"
                >Names</button>
            </div>

            @if ($editable)
            <div class="ws-plan__group">
                <button
                    type="button"
                    class="ws-plan__btn"
                    x-on:click="toggleFill()"
                    x-bind:aria-pressed="fillMode ? 'true' : 'false'"
                    x-bind:disabled="unplaced.length === 0"
                    title="Draw a box round a bank of desks on the drawing and fill it from the tray"
                >Fill an area</button>
            </div>
            @endif

            <div class="ws-plan__spacer"></div>

            <span class="ws-plan__meta">
                {{ rtrim(rtrim(number_format($floor->width_m, 1), '0'), '.') }} &times;
                {{ rtrim(rtrim(number_format($floor->depth_m, 1), '0'), '.') }} m
            </span>

            <span class="ws-plan__meta" x-text="`${placed.length} of ${desks.length} placed`"></span>

            @if ($editable)
            <div class="ws-plan__group">
                <button
                    type="button"
                    class="ws-plan__btn"
                    x-on:click="autoArrange()"
                    x-bind:disabled="unplaced.length === 0 || busy"
                >Auto-arrange remaining</button>

                <button
                    type="button"
                    class="ws-plan__btn"
                    x-on:click="clearPlacements()"
                    x-bind:disabled="placed.length === 0 || busy"
                >Clear plan</button>
            </div>
            @endif
        </div>

        {{-- The frame is the shape of whatever is inside it: the drawing
             when there is one, the floor's own metres when there is not.
             Framing a 1.4 drawing in a 1.5 window letterboxes it and leaves
             every pin sitting slightly off the desk it marks. --}}
        <div
            class="ws-plan__viewport"
            style="aspect-ratio: {{ $frame }};"
            x-ref="viewport"
            x-bind:data-panning="panning ? 'true' : 'false'"
            x-bind:data-mode="fillMode ? 'fill' : 'move'"
            x-on:pointerdown="onViewportPointerDown($event)"
            x-on:wheel.prevent="onWheel($event)"
        >
            <div class="ws-plan__stage" x-bind:style="stageStyle">
                <div
                    class="ws-plan__surface {{ $planImage ? '' : 'ws-plan__surface--blank' }}"
                    style="
                        @if ($shape) aspect-ratio: {{ $shape }}; @endif
                        background-size:
                            {{ 100 / max($floor->width_m / 5, 1) }}% {{ 100 / max($floor->depth_m / 5, 1) }}%,
                            {{ 100 / max($floor->width_m / 5, 1) }}% {{ 100 / max($floor->depth_m / 5, 1) }}%,
                            {{ 100 / max($floor->width_m, 1) }}% {{ 100 / max($floor->depth_m, 1) }}%,
                            {{ 100 / max($floor->width_m, 1) }}% {{ 100 / max($floor->depth_m, 1) }}%;
                    "
                    x-ref="surface"
                    x-on:pointerdown="onSurfacePointerDown($event)"
                    x-on:keydown="onSurfaceKeyDown($event)"
                >
                    @if ($planImage)
                        <img class="ws-plan__image" src="{{ $planImage }}" alt="{{ $floor->name }} floor plan" draggable="false">
                    @endif

                    {{-- One delegated handler on the surface above, not a set
                         per pin: at three hundred desks the difference is the
                         page opening promptly or not. --}}
                    <template x-if="drawnBox">
                        <div class="ws-plan__box" x-bind:style="boxStyle"></div>
                    </template>

                    <template x-for="desk in placed" x-bind:key="desk.id">
                        <button
                            type="button"
                            class="ws-plan__pin"
                            x-bind:style="`left: ${desk.x}%; top: ${desk.y}%;`"
                            x-bind:data-id="desk.id"
                            x-bind:data-selected="selectedId === desk.id ? 'true' : 'false'"
                            x-bind:data-details="desk.details ? 'true' : 'false'"
                            x-bind:aria-label="desk.details ? `${desk.name}, has details` : desk.name"
                        ><span class="ws-plan__pin-icon" aria-hidden="true"></span><span class="ws-plan__pin-name" x-text="desk.name"></span></button>
                    </template>
                </div>
            </div>
        </div>

        <div class="ws-plan__hint" x-show="! fillMode">
            @if ($editable)
                Click a desk for its details &middot; drag it to move it &middot;
                drag it off the plan to take it back &middot; drag on empty space to pan &middot;
                scroll to zoom &middot; tab to a desk and use the arrow keys to nudge it.
            @else
                Click a desk for its details &middot; drag on empty space to pan &middot;
                scroll to zoom. You can look at this plan but not rearrange it.
            @endif
        </div>

        <div class="ws-plan__hint" x-show="fillMode" x-cloak>
            Drag a box round a bank of desks on the drawing, then say how many across and down.
            Desks come off the tray in name order &middot; middle-drag to pan &middot; Escape to stop.
        </div>

        <div
            class="ws-plan__tray"
            x-on:pointerdown="onTrayPointerDown($event)"
            x-on:keydown="onTrayKeyDown($event)"
        >
            <div class="ws-plan__tray-head">
                <span class="ws-plan__tray-label">Not placed yet</span>
                <span class="ws-plan__meta" x-text="unplaced.length"></span>

                <input
                    type="search"
                    class="ws-plan__search"
                    placeholder="Filter by name"
                    aria-label="Filter unplaced workstations by name"
                    x-model="search"
                    x-show="unplaced.length > 12"
                >

                <span class="ws-plan__spacer"></span>

                <span
                    class="ws-plan__meta"
                    x-show="desks.length > capacity"
                    x-text="`${desks.length} desks on a floor with room for about ${capacity}`"
                ></span>
            </div>

            <template x-if="unplaced.length === 0">
                <p class="ws-plan__empty" x-text="desks.length ? 'Every desk on this floor is on the plan.' : 'This floor has no workstations yet.'"></p>
            </template>

            <div class="ws-plan__chips" x-show="unplaced.length > 0">
                {{-- Capped rather than virtualised. Nobody picks a desk out of
                     three hundred chips by eye — they filter — so the list only
                     has to stay light and say how much it is not showing. --}}
                <template x-for="desk in visibleUnplaced" x-bind:key="desk.id">
                    <button
                        type="button"
                        class="ws-plan__chip"
                        x-bind:data-id="desk.id"
                        x-bind:aria-label="`${desk.name}, not placed. Drag onto the plan, press Enter to drop it in the middle, or press D for its details.`"
                        x-text="desk.name"
                    ></button>
                </template>

                <span class="ws-plan__empty" x-show="hiddenUnplaced > 0" x-text="`+ ${hiddenUnplaced} more`"></span>
            </div>
        </div>

        {{-- The numbers for the box just drawn, anchored beside it. --}}
        <template x-if="pending">
            <div
                class="ws-plan__fill"
                x-bind:style="`left: ${pending.clientX}px; top: ${pending.clientY}px;`"
                x-on:keydown.escape.stop="cancelFill()"
            >
                <div class="ws-plan__fill-row">
                    <input type="number" min="1" max="100" x-model.number="pending.columns" aria-label="Desks across" x-ref="fillColumns">
                    <span class="ws-plan__meta">across</span>
                </div>

                <div class="ws-plan__fill-row">
                    <input type="number" min="1" max="100" x-model.number="pending.rows" aria-label="Desks down">
                    <span class="ws-plan__meta">down</span>
                </div>

                <p class="ws-plan__empty" style="margin-bottom: 0.5rem;" x-text="fillSummary"></p>

                <div class="ws-plan__group">
                    <button type="button" class="ws-plan__btn" x-on:click="applyFill()" x-bind:disabled="busy || unplaced.length === 0">Fill</button>
                    <button type="button" class="ws-plan__btn" x-on:click="cancelFill()">Cancel</button>
                </div>
            </div>
        </template>

        {{-- Follows the cursor while a desk is being dragged out of the tray. --}}
        <template x-if="dragging && dragging.fromTray">
            <div class="ws-plan__ghost" x-bind:style="`left: ${dragging.clientX}px; top: ${dragging.clientY}px;`">
                <span class="ws-plan__pin" style="position: static; transform: none;"><span class="ws-plan__pin-icon" aria-hidden="true"></span><span class="ws-plan__pin-name" x-text="dragging.name"></span></span>
            </div>
        </template>
    </div>

    @script
    <script>
        Alpine.data('workspaceFloorPlan', (desks, floor) => ({
            desks,
            floor,
            capacity: floor.capacity,
            editable: floor.editable,
            zoom: 1,
            panX: 0,
            panY: 0,
            snap: true,
            // The grid step is metres, not percent — a floor is a real room and
            // "snap to two metres" means the same thing on a 60 m floor as on a
            // 12 m one, where "snap to 2%" does not.
            step: 1,
            labels: desks.length <= 60,
            search: '',
            dragging: null,
            panning: null,
            selectedId: null,
            busy: false,

            // Filling an area: the box while it is being dragged, then the box
            // waiting for its numbers. Only one is ever set.
            box: null,
            pending: null,
            fillMode: false,

            // How many chips the tray draws before it stops and says how many
            // more there are.
            trayLimit: 60,

            get placed() {
                return this.desks.filter((d) => d.x !== null && d.y !== null)
            },

            get unplaced() {
                return this.desks.filter((d) => d.x === null || d.y === null)
            },

            get visibleUnplaced() {
                return this.filteredUnplaced.slice(0, this.trayLimit)
            },

            get filteredUnplaced() {
                const needle = this.search.trim().toLowerCase()

                return needle
                    ? this.unplaced.filter((d) => d.name.toLowerCase().includes(needle))
                    : this.unplaced
            },

            get hiddenUnplaced() {
                return Math.max(0, this.filteredUnplaced.length - this.trayLimit)
            },

            get stageStyle() {
                return `transform: translate(${this.panX}px, ${this.panY}px) scale(${this.zoom});`
            },

            get drawnBox() {
                return this.box ?? this.pending
            },

            get boxStyle() {
                const b = this.drawnBox

                if (! b) return 'display: none;'

                return `left: ${Math.min(b.x1, b.x2)}%; top: ${Math.min(b.y1, b.y2)}%;`
                    + ` width: ${Math.abs(b.x2 - b.x1)}%; height: ${Math.abs(b.y2 - b.y1)}%;`
            },

            get fillSummary() {
                if (! this.pending) return ''

                const asked = Math.max(1, this.pending.columns) * Math.max(1, this.pending.rows)
                const spare = this.unplaced.length

                return asked > spare
                    ? `${asked} desks, but only ${spare} left in the tray`
                    : `${asked} desks, ${spare - asked} left in the tray`
            },

            init() {
                // Bound once so the same reference can be removed again — an
                // arrow function created at addEventListener time never can be.
                this.onMove = (event) => this.handleMove(event)
                this.onUp = (event) => this.handleUp(event)

                // Escape leaves fill mode from anywhere, including mid-box.
                // A mode you cannot get out of without hunting for the button
                // that turned it on is a trap.
                this.$el.ownerDocument.addEventListener('keydown', (event) => {
                    if (event.key !== 'Escape' || ! this.fillMode) return

                    if (this.pending || this.box) {
                        this.cancelFill()
                    } else {
                        this.fillMode = false
                    }
                })

                // The modal writes through Livewire, which cannot reach into
                // this component: the whole plan sits behind wire:ignore so a
                // round trip never disturbs a drag. It reports back instead,
                // and only the one dot changes — no reload, no lost zoom.
                this.$wire.on('desk-details-saved', ({ workstation, hasDetails }) => {
                    const desk = this.find(Number(workstation))

                    if (desk) desk.details = Boolean(hasDetails)
                })
            },

            /** Opens the details modal for one desk. */
            openDetails(desk) {
                this.$wire.mountAction('deskDetails', { workstation: desk.id })
            },

            // ---- geometry -------------------------------------------------

            /**
             * A client point as a percentage of the plan surface. The bounding
             * rect is already scaled by the stage transform, so this stays
             * correct at any zoom without dividing anything out by hand.
             */
            toPercent(clientX, clientY) {
                const rect = this.$refs.surface.getBoundingClientRect()
                const x = ((clientX - rect.left) / rect.width) * 100
                const y = ((clientY - rect.top) / rect.height) * 100

                return { x, y, inside: x >= 0 && x <= 100 && y >= 0 && y <= 100 }
            },

            /** The snap step along an axis, converted from metres to percent. */
            stepPercent(axis) {
                const metres = axis === 'x' ? this.floor.width : this.floor.depth

                return metres > 0 ? (this.step / metres) * 100 : this.step
            },

            quantise(value, axis) {
                const bounded = Math.min(100, Math.max(0, value))

                if (! this.snap) return Math.round(bounded * 100) / 100

                const stride = this.stepPercent(axis)

                return Math.round(Math.round(bounded / stride) * stride * 100) / 100
            },

            // ---- view -----------------------------------------------------

            zoomBy(factor) {
                const rect = this.$refs.viewport.getBoundingClientRect()

                this.zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, factor)
            },

            /** Zooms about a point, keeping whatever is under it under it. */
            zoomAt(clientX, clientY, factor) {
                // A big floor has to zoom in much further before a desk is
                // comfortable to grab, so the ceiling scales with the room.
                const maxZoom = Math.max(5, this.floor.width / 6)
                const next = Math.min(maxZoom, Math.max(0.35, this.zoom * factor))
                const rect = this.$refs.viewport.getBoundingClientRect()
                const px = clientX - rect.left
                const py = clientY - rect.top
                const stageX = (px - this.panX) / this.zoom
                const stageY = (py - this.panY) / this.zoom

                this.panX = px - stageX * next
                this.panY = py - stageY * next
                this.zoom = next
            },

            onWheel(event) {
                this.zoomAt(event.clientX, event.clientY, event.deltaY < 0 ? 1.1 : 1 / 1.1)
            },

            resetView() {
                this.zoom = 1
                this.panX = 0
                this.panY = 0
            },

            // ---- delegated input ------------------------------------------

            deskFromEvent(event) {
                const el = event.target.closest('[data-id]')

                return el ? this.find(Number(el.dataset.id)) : null
            },

            onSurfacePointerDown(event) {
                const desk = this.deskFromEvent(event)

                if (! desk) return

                if (! this.editable) {
                    event.stopPropagation()
                    this.selectedId = desk.id
                    this.openDetails(desk)

                    return
                }

                // Claimed by a pin, so the viewport underneath must not also
                // read it as the start of a pan.
                event.stopPropagation()
                this.startDrag(desk, event)
            },

            onTrayPointerDown(event) {
                const desk = this.deskFromEvent(event)

                if (! desk) return

                if (! this.editable) {
                    event.stopPropagation()
                    this.openDetails(desk)

                    return
                }

                event.stopPropagation()
                this.startDrag(desk, event)
            },

            onTrayKeyDown(event) {
                const desk = this.deskFromEvent(event)

                if (! desk) return

                if ((event.key === 'Enter' || event.key === ' ') && this.editable) {
                    event.preventDefault()
                    this.commit(desk, 50, 50)

                    return
                }

                if (event.key === 'd' || event.key === 'D') {
                    event.preventDefault()
                    this.openDetails(desk)
                }
            },

            onSurfaceKeyDown(event) {
                const desk = this.deskFromEvent(event)

                if (! desk) return

                const nudges = {
                    ArrowLeft: [-1, 0],
                    ArrowRight: [1, 0],
                    ArrowUp: [0, -1],
                    ArrowDown: [0, 1],
                }

                if (nudges[event.key] && this.editable) {
                    event.preventDefault()
                    this.nudge(desk, ...nudges[event.key])

                    return
                }

                if ((event.key === 'Delete' || event.key === 'Backspace') && this.editable) {
                    event.preventDefault()
                    this.takeOff(desk)

                    return
                }

                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    this.selectedId = desk.id
                    this.openDetails(desk)
                }
            },

            // ---- dragging -------------------------------------------------

            /**
             * Empty space on the plan means "pan" normally and "draw a box" in
             * fill mode. The middle button keeps panning either way, so filling
             * a big drawing does not mean turning the mode off to move.
             */
            onViewportPointerDown(event) {
                if (this.fillMode && event.button === 0) {
                    this.startBox(event)

                    return
                }

                this.startPan(event)
            },

            toggleFill() {
                this.fillMode = ! this.fillMode
                this.cancelFill()
            },

            cancelFill() {
                this.box = null
                this.pending = null
            },

            startBox(event) {
                event.preventDefault()

                const point = this.toPercent(event.clientX, event.clientY)

                this.pending = null
                this.selectedId = null
                this.box = { x1: point.x, y1: point.y, x2: point.x, y2: point.y }

                this.listen()
            },

            /**
             * How many desks a box that size would hold, at the pitch of a real
             * open-plan bank: about 1.6 m across and 1.5 m front to back. The
             * numbers are editable — this only has to start close enough that
             * most boxes need no typing at all.
             */
            defaultGrid(box) {
                const across = (Math.abs(box.x2 - box.x1) / 100) * this.floor.width
                const down = (Math.abs(box.y2 - box.y1) / 100) * this.floor.depth

                return {
                    columns: Math.min(100, Math.max(1, Math.round(across / 1.6))),
                    rows: Math.min(100, Math.max(1, Math.round(down / 1.5))),
                }
            },

            finishBox(event) {
                const box = this.box

                this.box = null

                // Under a percent and a half each way it is a click that
                // happened to wobble, not a bank of desks.
                if (Math.abs(box.x2 - box.x1) < 1.5 || Math.abs(box.y2 - box.y1) < 1.5) return

                this.pending = {
                    x1: Math.min(box.x1, box.x2),
                    y1: Math.min(box.y1, box.y2),
                    x2: Math.max(box.x1, box.x2),
                    y2: Math.max(box.y1, box.y2),
                    // Beside the corner it was finished at, but kept on
                    // screen: a box drawn against the right-hand wall of the
                    // drawing would otherwise put its own numbers out of view.
                    clientX: Math.max(8, Math.min(event.clientX + 12, window.innerWidth - 260)),
                    clientY: Math.max(8, Math.min(event.clientY + 12, window.innerHeight - 200)),
                    ...this.defaultGrid(box),
                }

                this.$nextTick(() => this.$refs.fillColumns?.focus())
            },

            async applyFill() {
                const fill = this.pending

                if (! fill) return

                this.busy = true
                this.pending = null

                try {
                    this.desks = await this.$wire.fillArea(
                        fill.x1,
                        fill.y1,
                        fill.x2,
                        fill.y2,
                        Math.max(1, Math.min(100, fill.columns)),
                        Math.max(1, Math.min(100, fill.rows)),
                    )
                } finally {
                    this.busy = false
                }
            },

            startPan(event) {
                if (event.button !== 0 && event.button !== 1) return

                this.selectedId = null
                this.panning = {
                    startX: event.clientX,
                    startY: event.clientY,
                    originX: this.panX,
                    originY: this.panY,
                }

                this.listen()
            },

            startDrag(desk, event) {
                if (event.button !== 0) return

                event.preventDefault()

                this.selectedId = desk.id
                this.dragging = {
                    id: desk.id,
                    name: desk.name,
                    fromTray: desk.x === null || desk.y === null,
                    clientX: event.clientX,
                    clientY: event.clientY,
                    // Where the press started, so a click can be told from a
                    // drag on release. Without this, clicking a pin would
                    // "move" it to where it already was and never open
                    // anything.
                    downX: event.clientX,
                    downY: event.clientY,
                    moved: false,
                    // Kept so a drag that ends outside the plan can put the
                    // desk back exactly where it started rather than guessing.
                    fromX: desk.x,
                    fromY: desk.y,
                }

                this.listen()
            },

            listen() {
                window.addEventListener('pointermove', this.onMove)
                window.addEventListener('pointerup', this.onUp)
                window.addEventListener('pointercancel', this.onUp)
            },

            unlisten() {
                window.removeEventListener('pointermove', this.onMove)
                window.removeEventListener('pointerup', this.onUp)
                window.removeEventListener('pointercancel', this.onUp)
            },

            handleMove(event) {
                if (this.box) {
                    const point = this.toPercent(event.clientX, event.clientY)

                    this.box.x2 = Math.min(100, Math.max(0, point.x))
                    this.box.y2 = Math.min(100, Math.max(0, point.y))

                    return
                }

                if (this.panning) {
                    this.panX = this.panning.originX + (event.clientX - this.panning.startX)
                    this.panY = this.panning.originY + (event.clientY - this.panning.startY)

                    return
                }

                if (! this.dragging) return

                this.dragging.clientX = event.clientX
                this.dragging.clientY = event.clientY

                // Four pixels of slop: a click made with a real mouse is never
                // perfectly still, and treating a two-pixel wobble as a drag
                // would move a desk every time somebody opened its details.
                if (Math.hypot(event.clientX - this.dragging.downX, event.clientY - this.dragging.downY) > 4) {
                    this.dragging.moved = true
                }

                if (! this.dragging.moved) return

                // A desk already on the plan moves under the cursor as it is
                // dragged; one still in the tray is represented by the ghost
                // until it is dropped, so the tray does not reshuffle mid-drag.
                if (! this.dragging.fromTray) {
                    const point = this.toPercent(event.clientX, event.clientY)
                    const desk = this.find(this.dragging.id)

                    if (desk) {
                        desk.x = this.quantise(point.x, 'x')
                        desk.y = this.quantise(point.y, 'y')
                    }
                }
            },

            handleUp(event) {
                this.unlisten()

                if (this.box) {
                    this.finishBox(event)

                    return
                }

                if (this.panning) {
                    this.panning = null

                    return
                }

                if (! this.dragging) return

                const drag = this.dragging
                const desk = this.find(drag.id)

                this.dragging = null

                if (! desk) return

                // A press that never moved is a click, not a drag: open the
                // desk rather than committing a placement it already had.
                if (! drag.moved) {
                    this.openDetails(desk)

                    return
                }

                const point = this.toPercent(event.clientX, event.clientY)

                if (point.inside) {
                    this.commit(desk, point.x, point.y)

                    return
                }

                // Dropped outside the plan. A desk that was on it comes off;
                // one dragged out of the tray simply stays there.
                if (drag.fromTray) return

                desk.x = drag.fromX
                desk.y = drag.fromY

                this.takeOff(desk)
            },

            // ---- persistence ----------------------------------------------

            find(id) {
                return this.desks.find((d) => d.id === id)
            },

            commit(desk, x, y) {
                if (! this.editable) return

                const previousX = desk.x
                const previousY = desk.y

                desk.x = this.quantise(x, 'x')
                desk.y = this.quantise(y, 'y')
                this.selectedId = desk.id

                this.$wire.place(desk.id, desk.x, desk.y).catch(() => {
                    desk.x = previousX
                    desk.y = previousY
                })
            },

            takeOff(desk) {
                if (! this.editable) return

                const previousX = desk.x
                const previousY = desk.y

                desk.x = null
                desk.y = null

                if (this.selectedId === desk.id) this.selectedId = null

                this.$wire.unplace(desk.id).catch(() => {
                    desk.x = previousX
                    desk.y = previousY
                })
            },

            nudge(desk, dx, dy) {
                const strideX = this.snap ? this.stepPercent('x') : 0.25
                const strideY = this.snap ? this.stepPercent('y') : 0.25

                this.commit(desk, desk.x + dx * strideX, desk.y + dy * strideY)
            },

            async autoArrange() {
                this.busy = true

                try {
                    this.desks = await this.$wire.autoArrange()
                } finally {
                    this.busy = false
                }
            },

            async clearPlacements() {
                this.busy = true

                try {
                    this.desks = await this.$wire.clearPlacements()
                    this.selectedId = null
                } finally {
                    this.busy = false
                }
            },
        }))
    </script>
    @endscript
</x-filament-panels::page>

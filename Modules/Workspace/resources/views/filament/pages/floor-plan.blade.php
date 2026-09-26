{{--
    The floor map editor.

    The markup here is the frame — bars, toolbar, panel, tray, dialogs — bound
    to the Alpine component in resources/js/floor-map.js, which Filament loads
    on demand (x-load) from this server. The map itself is SVG the component
    writes directly; see the comment at the top of that file for why.

    wire:ignore: Livewire must never re-render this subtree, or a round trip
    for the details modal would wipe the unsaved draft.
--}}
@php
    use Filament\Support\Facades\FilamentAsset;
    use Modules\Workspace\Enums\WorkstationStatus;

    $config = $this->mapConfig();
    $primary = collect($config['types'])->where('primary', true);
    $more = collect($config['types'])->where('primary', false);
    $icons = collect(app(\Modules\Workspace\FloorMap\FloorObjectTypes::class)->all())->map(fn ($type) => $type->icon);
@endphp

<x-filament-panels::page>
    <link rel="stylesheet" href="{{ FilamentAsset::getStyleHref('floor-map', 'app') }}">

    <div
        class="fm"
        wire:ignore
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('floor-map', 'app') }}"
        x-data="floorMap(@js($config))"
        x-on:keydown.window="onKey($event)"
        x-on:pointermove.window="onPointerMove($event)"
        x-on:pointerup.window="onPointerUp($event)"
        data-floor-map
    >
        {{-- ---- top bar ---- --}}
        <div class="fm-bar">
            <select class="fm-select" aria-label="Floor" x-on:change="goToFloor($event.target.value)">
                @foreach ($config['floors'] as $option)
                    <option value="{{ $option['url'] }}" @selected($option['current'])>{{ $option['label'] }}</option>
                @endforeach
            </select>

            <form class="fm-group" x-on:submit.prevent="find()">
                <input
                    type="search"
                    class="fm-input fm-input--search"
                    placeholder="Find a workstation"
                    aria-label="Find a workstation: ID, PC name, port or IP"
                    title="A workstation ID, PC name, port or IP. Desks on other floors are found too."
                    x-model="search"
                    x-bind:class="{ 'is-miss': searchMiss }"
                >
            </form>

            <span class="fm-divider"></span>

            <div class="fm-group">
                <button type="button" class="fm-btn fm-btn--icon" title="Zoom out" x-on:click="zoomBy(1 / 1.25)">
                    <x-filament::icon icon="heroicon-o-magnifying-glass-minus" />
                </button>
                <button type="button" class="fm-btn fm-btn--icon" title="Zoom in" x-on:click="zoomBy(1.25)">
                    <x-filament::icon icon="heroicon-o-magnifying-glass-plus" />
                </button>
                <button type="button" class="fm-btn" title="Fit the floor to the window" x-on:click="fit()">
                    <x-filament::icon icon="heroicon-o-arrows-pointing-in" /> Fit
                </button>
            </div>

            <div class="fm-group" role="group" aria-label="View">
                <button type="button" class="fm-btn" x-on:click="setProjection('top')" x-bind:aria-pressed="projection === 'top'">Plan</button>
                <button type="button" class="fm-btn" x-on:click="setProjection('iso')" x-bind:aria-pressed="projection === 'iso'">
                    <x-filament::icon icon="heroicon-o-cube-transparent" /> 3D
                </button>
                <button type="button" class="fm-btn" x-show="projection === 'iso'" x-on:click="toggleCutaway()" x-bind:aria-pressed="cutaway" title="Draw walls low so desks behind them stay visible">Low walls</button>
            </div>

            <span class="fm-spacer"></span>

            <span class="fm-dirty" x-show="dirty" x-cloak>Unsaved changes</span>

            <template x-if="canArrange">
                <div class="fm-group" role="group" aria-label="Mode">
                    <button type="button" class="fm-btn" x-on:click="setMode('view')" x-bind:aria-pressed="mode === 'view'">
                        <x-filament::icon icon="heroicon-o-eye" /> View
                    </button>
                    <button type="button" class="fm-btn" x-on:click="setMode('edit')" x-bind:aria-pressed="mode === 'edit'">
                        <x-filament::icon icon="heroicon-o-pencil-square" /> Edit
                    </button>
                </div>
            </template>

            <div class="fm-group" x-show="isEditing" x-cloak>
                <button type="button" class="fm-btn fm-btn--icon" title="Undo (Ctrl+Z)" x-on:click="undo()" x-bind:disabled="!canUndo">
                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" />
                </button>
                <button type="button" class="fm-btn fm-btn--icon" title="Redo (Ctrl+Y)" x-on:click="redo()" x-bind:disabled="!canRedo">
                    <x-filament::icon icon="heroicon-o-arrow-uturn-right" />
                </button>
                <button type="button" class="fm-btn fm-btn--primary" x-on:click="save()" x-bind:disabled="!dirty || saving">
                    <span x-text="saving ? 'Saving…' : 'Save'"></span>
                </button>
            </div>
        </div>

        {{-- ---- toolbar, map, panel ---- --}}
        <div class="fm-body" x-bind:class="{ 'is-viewing': !isEditing }">
            <nav class="fm-tools" x-show="isEditing" x-cloak aria-label="Tools">
                <button type="button" class="fm-tool" x-on:click="setTool('select')" x-bind:aria-pressed="tool === 'select'" title="Select, move, rotate, resize (V)">
                    <x-filament::icon icon="heroicon-o-cursor-arrow-rays" /> Select
                </button>

                @foreach ($primary as $type)
                    <button type="button" class="fm-tool" x-on:click="setTool(@js($type['key']))" x-bind:aria-pressed="tool === @js($type['key'])" title="{{ $type['label'] }}">
                        <x-filament::icon :icon="$icons[$type['key']]" /> {{ $type['label'] }}
                    </button>
                @endforeach

                <button type="button" class="fm-tool" x-on:click="setTool('fill')" x-bind:aria-pressed="tool === 'fill'" title="Draw a box round a bank of desks and fill it from the tray">
                    <x-filament::icon icon="heroicon-o-table-cells" /> Fill area
                </button>

                <p class="fm-tools-label">More</p>

                @foreach ($more as $type)
                    <button type="button" class="fm-tool" x-on:click="setTool(@js($type['key']))" x-bind:aria-pressed="tool === @js($type['key'])" title="{{ $type['label'] }}">
                        <x-filament::icon :icon="$icons[$type['key']]" /> {{ $type['label'] }}
                    </button>
                @endforeach

                <p class="fm-tools-label">Edit</p>

                <button type="button" class="fm-tool" x-on:click="duplicateSelection()" x-bind:disabled="!selection.length" title="Duplicate (Ctrl+D)">
                    <x-filament::icon icon="heroicon-o-document-duplicate" /> Duplicate
                </button>
                <button type="button" class="fm-tool" x-on:click="rotateSelection(90)" x-bind:disabled="!selection.length" title="Rotate 90° (R)">
                    <x-filament::icon icon="heroicon-o-arrow-path" /> Rotate
                </button>
                <button type="button" class="fm-tool fm-tool--danger" x-on:click="deleteSelection()" x-bind:disabled="!selection.length" title="Delete (Del)">
                    <x-filament::icon icon="heroicon-o-trash" /> Delete
                </button>
            </nav>

            <div class="fm-stage" x-bind:data-tool="isEditing ? tool : 'select'">
                <svg
                    x-ref="svg"
                    class="fm-svg"
                    xmlns="http://www.w3.org/2000/svg"
                    role="application"
                    aria-label="{{ $config['floor']['name'] }} map"
                    x-on:pointerdown="onPointerDown($event)"
                    x-on:dblclick="onDoubleClick($event)"
                    x-on:wheel.prevent="onWheel($event)"
                    x-on:contextmenu.prevent
                >
                    <g x-ref="viewport">
                        <g x-ref="plane" data-grid="on"></g>
                        <g x-ref="glow" class="fm-glow"></g>
                        <g x-ref="objects"></g>
                        <g x-ref="overlay"></g>
                    </g>
                </svg>

                {{-- The name over a desk that has just been located. --}}
                <template x-if="callout">
                    <div class="fm-callout" role="status" x-bind:style="`left:${callout.left}px;top:${callout.top}px`">
                        <strong x-text="callout.name"></strong>
                        <span class="fm-status" x-show="callout.statusLabel" x-bind:style="`--fm-status:${callout.statusColor}`" x-text="callout.statusLabel"></span>
                    </div>
                </template>

                <div class="fm-marquee" x-show="marquee" x-cloak
                    x-bind:style="marquee && `left:${marquee.left}px;top:${marquee.top}px;width:${marquee.width}px;height:${marquee.height}px`"></div>

                {{-- Fill an area: how many across and down. --}}
                <template x-if="fill"><div class="fm-dialog">
                    <h3>Fill this area from the tray</h3>
                    <p x-text="`${Math.min(tray.length, (fill.columns || 1) * (fill.rows || 1))} of ${tray.length} desks in the tray, in name order.`"></p>

                    <div class="fm-fields">
                        <label class="fm-field"><span>Across</span><input type="number" min="1" max="100" class="fm-input" x-model.number="fill.columns"></label>
                        <label class="fm-field"><span>Down</span><input type="number" min="1" max="100" class="fm-input" x-model.number="fill.rows"></label>
                        <label class="fm-field fm-field--full"><span>Facing</span>
                            <select class="fm-select" x-model.number="fill.rotation">
                                <option value="0">Up</option>
                                <option value="90">Right</option>
                                <option value="180">Down</option>
                                <option value="270">Left</option>
                            </select>
                        </label>
                    </div>

                    <div class="fm-actions">
                        <button type="button" class="fm-btn fm-btn--primary" x-on:click="applyFill()">Place desks</button>
                        <button type="button" class="fm-btn" x-on:click="fill = null">Cancel</button>
                    </div>
                </div></template>

                {{-- No desk left in the tray: make one. --}}
                <template x-if="newDesk"><div class="fm-dialog">
                    <h3>New workstation</h3>
                    <p>Every desk on this floor is already on the map. Give the new one its Workstation ID.</p>

                    <form x-on:submit.prevent="createDesk()">
                        <label class="fm-field"><span>Workstation ID</span>
                            <input type="text" maxlength="100" class="fm-input" placeholder="WS-025" x-model="newDesk.name">
                        </label>
                        <p class="fm-error" x-show="newDesk.error" x-text="newDesk.error"></p>

                        <div class="fm-actions">
                            <button type="submit" class="fm-btn fm-btn--primary" x-bind:disabled="newDesk.busy">Create and place</button>
                            <button type="button" class="fm-btn" x-on:click="newDesk = null">Cancel</button>
                        </div>
                    </form>
                </div></template>

                {{-- Somebody else saved first. --}}
                <div class="fm-dialog fm-dialog--danger" x-show="conflict" x-cloak>
                    <h3>This map has changed</h3>
                    <p>Somebody else saved this floor's map after you opened it. Reload to see their version; your unsaved changes here cannot be merged with it.</p>

                    <div class="fm-actions">
                        <button type="button" class="fm-btn fm-btn--primary" x-on:click="reload()">Reload</button>
                        <button type="button" class="fm-btn" x-on:click="conflict = false">Stay here</button>
                    </div>
                </div>
            </div>

            {{-- ---- properties panel ---- --}}
            <aside class="fm-panel" aria-label="Properties">
                <template x-if="panel && !panel.multiple">
                    <div class="fm-section">
                        <h3 x-text="panel.typeLabel"></h3>

                        <template x-if="panel.desk">
                            <div>
                                <p class="fm-title" x-text="panel.desk.name"></p>
                                <p class="fm-status" x-bind:style="`--fm-status:${panel.desk.statusColor}`" x-text="panel.desk.statusLabel"></p>
                                <dl class="fm-facts">
                                    <template x-if="panel.desk.computer"><div><dt>PC</dt><dd x-text="panel.desk.computer"></dd></div></template>
                                    <template x-if="panel.desk.port"><div><dt>Port</dt><dd class="fm-mono" x-text="panel.desk.port"></dd></div></template>
                                    <template x-if="panel.desk.ip"><div><dt>IP</dt><dd class="fm-mono" x-text="panel.desk.ip"></dd></div></template>
                                </dl>
                                <div class="fm-actions">
                                    <button type="button" class="fm-btn" x-on:click="openDetails(panel.desk.id)">Workstation details</button>
                                    <template x-if="historyUrl(panel.desk.id)">
                                        <a class="fm-btn" x-bind:href="historyUrl(panel.desk.id)">History</a>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="!panel.desk">
                            <label class="fm-field fm-field--full">
                                <span>Label</span>
                                <input type="text" maxlength="100" class="fm-input" x-bind:value="panel.label" x-bind:disabled="!isEditing" x-on:change="setField('label', $event.target.value)">
                            </label>
                        </template>

                        <div class="fm-fields" style="margin-top: 0.625rem;">
                            <label class="fm-field"><span>X (m)</span><input type="number" step="0.1" class="fm-input" x-bind:value="panel.x" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('x', $event.target.value)"></label>
                            <label class="fm-field"><span>Y (m)</span><input type="number" step="0.1" class="fm-input" x-bind:value="panel.y" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('y', $event.target.value)"></label>
                            <template x-if="panel.resizable">
                                <label class="fm-field"><span>Width (m)</span><input type="number" step="0.1" min="0.05" class="fm-input" x-bind:value="panel.width" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('width', $event.target.value)"></label>
                            </template>
                            <template x-if="panel.resizable">
                                <label class="fm-field"><span>Depth (m)</span><input type="number" step="0.1" min="0.05" class="fm-input" x-bind:value="panel.depth" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('depth', $event.target.value)"></label>
                            </template>
                            <label class="fm-field"><span>Height (m)</span><input type="number" step="0.1" min="0" class="fm-input" x-bind:value="panel.height" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('height', $event.target.value)"></label>
                            <label class="fm-field"><span>Elevation (m)</span><input type="number" step="0.1" class="fm-input" x-bind:value="panel.z" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('z', $event.target.value)"></label>
                            <label class="fm-field fm-field--full"><span>Rotation (°)</span><input type="number" step="15" class="fm-input" x-bind:value="panel.rotation" x-bind:disabled="!isEditing || panel.locked" x-on:change="setField('rotation', $event.target.value)"></label>

                            <template x-if="panel.props.includes('color')">
                                <label class="fm-field"><span>Colour</span><input type="color" class="fm-input" x-bind:value="panel.color" x-bind:disabled="!isEditing" x-on:change="setField('color', $event.target.value)"></label>
                            </template>
                            <template x-if="panel.props.includes('text')">
                                <label class="fm-field fm-field--full"><span>Text</span><input type="text" maxlength="200" class="fm-input" x-bind:value="panel.text" x-bind:disabled="!isEditing" x-on:change="setField('text', $event.target.value)"></label>
                            </template>
                            <template x-if="panel.props.includes('size')">
                                <label class="fm-field"><span>Text size (m)</span><input type="number" step="0.1" min="0.1" max="10" class="fm-input" x-bind:value="panel.size" x-bind:disabled="!isEditing" x-on:change="setField('size', $event.target.value)"></label>
                            </template>
                            <template x-if="panel.props.includes('swing')">
                                <label class="fm-field"><span>Opens</span>
                                    <select class="fm-select" x-bind:disabled="!isEditing" x-on:change="setField('swing', $event.target.value)">
                                        <option value="left" x-bind:selected="panel.swing === 'left'">Left</option>
                                        <option value="right" x-bind:selected="panel.swing === 'right'">Right</option>
                                    </select>
                                </label>
                            </template>
                        </div>

                        <template x-if="isEditing">
                            <div>
                                <label class="fm-check" style="margin-top: 0.625rem;">
                                    <input type="checkbox" x-bind:checked="panel.locked" x-on:change="setField('locked', $event.target.checked)"> Locked in place
                                </label>
                                <div class="fm-actions">
                                    <button type="button" class="fm-btn" x-on:click="rotateSelection(90)">Rotate 90°</button>
                                    <button type="button" class="fm-btn" x-on:click="duplicateSelection()">Duplicate</button>
                                    <button type="button" class="fm-btn fm-btn--danger" x-on:click="deleteSelection()" x-bind:disabled="panel.locked">Delete</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="panel && panel.multiple">
                    <div class="fm-section">
                        <h3>Selection</h3>
                        <p class="fm-title" x-text="`${panel.multiple} objects`"></p>
                        <div class="fm-actions" x-show="isEditing">
                            <button type="button" class="fm-btn" x-on:click="rotateSelection(90)">Rotate 90°</button>
                            <button type="button" class="fm-btn" x-on:click="duplicateSelection()">Duplicate</button>
                            <button type="button" class="fm-btn fm-btn--danger" x-on:click="deleteSelection()">Delete</button>
                        </div>
                    </div>
                </template>

                <template x-if="!panel">
                    <div class="fm-section">
                        <h3>{{ $config['floor']['name'] }}</h3>
                        <p class="fm-empty" x-show="!isEditing">Click a desk for its details. Drag to pan, scroll to zoom.</p>
                        <p class="fm-empty" x-show="isEditing">Pick a tool on the left and click the map, or drag walls and rooms out. Drag desks in from the tray below. Shift-drag to select several.</p>
                    </div>
                </template>

                {{-- Desks not on the map yet. --}}
                <div class="fm-section fm-tray" x-show="isEditing" x-cloak>
                    <h3 x-text="`Not on the map · ${tray.length}`"></h3>

                    <input type="search" class="fm-input" placeholder="Filter" aria-label="Filter the desks not on the map" x-model="trayFilter" x-show="tray.length > 12">

                    <div class="fm-tray-list">
                        <template x-for="desk in filteredTray.slice(0, 120)" x-bind:key="desk.id">
                            <button
                                type="button"
                                class="fm-chip"
                                x-text="desk.name"
                                x-bind:aria-pressed="pendingDesk === desk.id"
                                x-on:click="pickDesk(desk.id)"
                                x-on:pointerdown="startTrayDrag($event, desk)"
                                title="Drag onto the map, or click then click the map"
                            ></button>
                        </template>
                    </div>

                    <p class="fm-empty" x-show="!tray.length">Every desk on this floor is on the map.</p>

                    <div class="fm-actions">
                        <button type="button" class="fm-btn" x-on:click="autoArrange()" x-bind:disabled="!tray.length">Arrange the rest</button>
                        <button type="button" class="fm-btn fm-btn--danger" x-on:click="clearDesks()" x-bind:disabled="!counts.placed">Clear desks</button>
                    </div>
                </div>

                <div class="fm-section">
                    <h3>Status</h3>
                    <div class="fm-legend">
                        @foreach (WorkstationStatus::cases() as $status)
                            <span class="fm-status" style="--fm-status: {{ $status->mapColor() }}">{{ $status->getLabel() }}</span>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>

        {{-- ---- bottom bar ---- --}}
        <div class="fm-bar fm-bar--bottom">
            <span x-text="`${Math.round(zoom * 100)}%`"></span>
            <span class="fm-divider"></span>

            <label class="fm-check"><input type="checkbox" x-bind:checked="grid" x-on:change="toggleGrid()"> Grid</label>
            <label class="fm-check"><input type="checkbox" x-model="snap"> Snap</label>
            <select class="fm-select" x-model.number="step" x-bind:disabled="!snap" aria-label="Snap step">
                <option value="0.1">0.1 m</option>
                <option value="0.25">0.25 m</option>
                <option value="0.5">0.5 m</option>
                <option value="1">1 m</option>
            </select>
            <label class="fm-check"><input type="checkbox" x-bind:checked="labels" x-on:change="toggleLabels()"> Names</label>

            <span class="fm-divider"></span>
            <span x-text="cursor ? `x ${cursor.x.toFixed(2)} m · y ${cursor.y.toFixed(2)} m` : 'x — · y —'"></span>

            <span class="fm-spacer"></span>

            <span x-text="`${counts.placed} of ${counts.desks} desks on the map · ${counts.objects} objects`"></span>
            <span>{{ rtrim(rtrim(number_format($config['floor']['width'], 1), '0'), '.') }} × {{ rtrim(rtrim(number_format($config['floor']['depth'], 1), '0'), '.') }} m</span>
        </div>

        <div class="fm-ghost" x-show="ghost" x-cloak x-bind:style="ghost && `left:${ghost.left}px;top:${ghost.top}px`" x-text="ghost?.name"></div>
    </div>
</x-filament-panels::page>
